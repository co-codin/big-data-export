<?php

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Jobs\FinalizeReportJob;
use App\Jobs\GenerateReportChunkJob;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ReportProcess;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GenerateReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_category_records_an_error_row_and_dispatches_nothing(): void
    {
        Bus::fake();

        $this->artisan('report:generate', ['category_id' => 999])
            ->expectsOutputToContain('Ошибка: для категории 999 не найдено товаров.')
            ->assertExitCode(1);

        $this->assertSame(1, ReportProcess::count(), 'rejected attempt is still recorded');
        $row = ReportProcess::first();
        $this->assertSame(ProcessStatusId::Error, $row->ps_id);
        $this->assertNull($row->rp_file_save_path);
        Bus::assertNothingBatched();
    }

    public function test_rejects_non_positive_category(): void
    {
        $this->artisan('report:generate', ['category_id' => 0])->assertExitCode(2);
    }

    public function test_throws_when_chunk_size_config_is_non_positive(): void
    {
        Config::set('reports.chunk_size', 0);

        $mfr = Manufacturer::factory()->create();
        Product::factory()->for($mfr)->create(['category_id' => 42]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reports.chunk_size must be a positive integer');

        $this->artisan('report:generate', ['category_id' => 42]);
    }

    public function test_dispatches_one_batch_per_manufacturer_with_chunked_jobs(): void
    {
        Bus::fake();
        Config::set('reports.chunk_size', 2);  // force multiple chunks even on small data

        $acme = Manufacturer::factory()->create(['manufacturer_name' => 'Acme']);
        $beta = Manufacturer::factory()->create(['manufacturer_name' => 'Beta']);

        // 5 acme products in cat 7 → ceil(5/2) = 3 chunks
        Product::factory()->count(5)->for($acme)->create(['category_id' => 7]);
        // 2 beta products in cat 7 → 1 chunk
        Product::factory()->count(2)->for($beta)->create(['category_id' => 7]);

        $this->artisan('report:generate', ['category_id' => 7])->assertExitCode(0);

        // One report_process row per manufacturer, all in Запуск.
        $this->assertSame(2, ReportProcess::count());
        $this->assertSame(2, ReportProcess::where('ps_id', ProcessStatusId::Started->value)->count());

        Bus::assertBatchCount(2);
        Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 3 && str_starts_with($b->name, 'report:'));
        Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 1 && str_starts_with($b->name, 'report:'));

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->every(fn ($j) => $j instanceof GenerateReportChunkJob);
        });
    }

    /**
     * Verifies the success branch of the finally-callback closure registered
     * on Bus::batch: a successful batch must dispatch FinalizeReportJob with
     * hadFailures=false and the correct file/dir arguments derived from rp_id.
     *
     * Note: a true async end-to-end is not feasible under PHPUnit because
     * Bus::batch + SyncQueue interact badly (total_jobs is incremented after
     * jobs run, so the pending counter never lands at 0 and finally never
     * fires). Real async behavior is exercised by the worker container.
     */
    public function test_finally_callback_dispatches_finalize_when_batch_succeeds(): void
    {
        Bus::fake();

        $mfr = Manufacturer::factory()->create();
        Product::factory()->for($mfr)->create(['category_id' => 21]);

        $this->artisan('report:generate', ['category_id' => 21])->assertExitCode(0);

        $batches = Bus::dispatchedBatches();
        $batch = $batches[0] ?? null;
        $this->assertNotNull($batch);
        $callbacks = $batch->finallyCallbacks();
        $this->assertNotEmpty($callbacks, 'finally callback must be registered');

        $successfulBatch = $this->createMock(Batch::class);
        $successfulBatch->method('hasFailures')->willReturn(false);

        foreach ($callbacks as $callback) {
            $callback($successfulBatch);
        }

        $rp = ReportProcess::first();
        Bus::assertDispatched(FinalizeReportJob::class, function (FinalizeReportJob $job) use ($rp) {
            return $job->hadFailures === false
                && $job->reportProcessId === (int) $rp->rp_id
                && str_starts_with($job->outputFileName, 'report_')
                && str_ends_with($job->outputFileName, '.csv')
                && str_contains($job->tmpRelativeDir, (string) $rp->rp_id);
        });
    }

    /**
     * Two same-second dispatches for the same (manufacturer, category)
     * must produce DIFFERENT output filenames — the rp_id suffix
     * guarantees uniqueness even when format('Y-m-d_H-i-s') collides.
     */
    public function test_concurrent_same_second_dispatches_produce_unique_filenames(): void
    {
        Bus::fake();

        $mfr = Manufacturer::factory()->create();
        Product::factory()->for($mfr)->create(['category_id' => 50]);

        // Freeze time so both dispatches share the exact same Y-m-d_H-i-s.
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        try {
            $this->artisan('report:generate', ['category_id' => 50])->assertExitCode(0);
            $this->artisan('report:generate', ['category_id' => 50])->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $batches = Bus::dispatchedBatches();
        $this->assertCount(2, $batches);

        // Trigger both finally callbacks with a success-stub Batch so we can
        // inspect the FinalizeReportJob that each one dispatches.
        $successBatch = $this->createMock(Batch::class);
        $successBatch->method('hasFailures')->willReturn(false);
        foreach ($batches as $batch) {
            foreach ($batch->finallyCallbacks() as $cb) {
                $cb($successBatch);
            }
        }

        $fileNames = [];
        Bus::assertDispatched(FinalizeReportJob::class, function (FinalizeReportJob $job) use (&$fileNames) {
            $fileNames[] = $job->outputFileName;

            return true;
        });

        $this->assertCount(2, $fileNames);
        $this->assertNotEquals(
            $fileNames[0],
            $fileNames[1],
            'second-precision timestamp shared, but the rp_id suffix must keep filenames unique'
        );
    }

    /**
     * Mirror of the previous test for the failure branch: a batch with
     * failures must dispatch FinalizeReportJob with hadFailures=true,
     * which (see FinalizeReportJobTest) flips the row to Ошибка.
     */
    public function test_finally_callback_dispatches_finalize_when_batch_has_failures(): void
    {
        Bus::fake();

        $mfr = Manufacturer::factory()->create();
        Product::factory()->for($mfr)->create(['category_id' => 30]);

        $this->artisan('report:generate', ['category_id' => 30])->assertExitCode(0);

        $batches = Bus::dispatchedBatches();
        $batch = $batches[0] ?? null;
        $this->assertNotNull($batch);

        $failedBatch = $this->createMock(Batch::class);
        $failedBatch->method('hasFailures')->willReturn(true);

        foreach ($batch->finallyCallbacks() as $callback) {
            $callback($failedBatch);
        }

        Bus::assertDispatched(
            FinalizeReportJob::class,
            fn (FinalizeReportJob $job) => $job->hadFailures === true,
        );
    }
}
