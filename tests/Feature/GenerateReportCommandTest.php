<?php

namespace Tests\Feature;

use App\Jobs\FinalizeReportJob;
use App\Jobs\GenerateReportChunkJob;
use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\ProcessStatus;
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
        $this->assertSame(ProcessStatus::ERROR, (int) $row->ps_id);
        $this->assertNull($row->rp_file_save_path);
        Bus::assertNothingBatched();
    }

    public function test_rejects_non_positive_category(): void
    {
        $this->artisan('report:generate', ['category_id' => 0])->assertExitCode(2);
    }

    public function test_dispatches_one_batch_per_manufacturer_with_chunked_jobs(): void
    {
        Bus::fake();
        Config::set('reports.chunk_size', 2);  // force multiple chunks even on small data

        $acme = Manufacturer::create(['manufacturer_name' => 'Acme']);
        $beta = Manufacturer::create(['manufacturer_name' => 'Beta']);

        // 5 acme products in cat 7 → ceil(5/2) = 3 chunks
        for ($i = 0; $i < 5; $i++) {
            Product::create(['product_name' => "A$i", 'category_id' => 7, 'manufacturer_id' => $acme->manufacturer_id]);
        }
        // 2 beta products in cat 7 → 1 chunk
        for ($i = 0; $i < 2; $i++) {
            Product::create(['product_name' => "B$i", 'category_id' => 7, 'manufacturer_id' => $beta->manufacturer_id]);
        }

        $this->artisan('report:generate', ['category_id' => 7])->assertExitCode(0);

        // One report_process row per manufacturer, all in Запуск.
        $this->assertSame(2, ReportProcess::count());
        $this->assertSame(2, ReportProcess::where('ps_id', ProcessStatus::STARTED)->count());

        Bus::assertBatchCount(2);
        Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 3 && str_starts_with($b->name, 'report:'));
        Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 1 && str_starts_with($b->name, 'report:'));

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->every(fn ($j) => $j instanceof GenerateReportChunkJob);
        });
    }

    public function test_sync_runs_full_pipeline_inline_and_writes_one_merged_file_per_manufacturer(): void
    {
        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);

        // 3 products with prices in the last 7 days → multiple chunks if chunk_size=2.
        Config::set('reports.chunk_size', 2);
        $products = [];
        foreach (['Widget', 'Gadget', 'Gizmo'] as $name) {
            $p = Product::create(['product_name' => $name, 'category_id' => 8, 'manufacturer_id' => $mfr->manufacturer_id]);
            Price::create(['product_id' => $p->product_id, 'price' => 10.00, 'price_date' => Carbon::today()->subDays(5)]);
            Price::create(['product_id' => $p->product_id, 'price' => 99.99, 'price_date' => Carbon::today()->subDays(1)]);
            $products[] = $p;
        }

        $this->artisan('report:generate', ['category_id' => 8, '--sync' => true])->assertExitCode(0);

        $process = ReportProcess::first();
        $this->assertNotNull($process);
        $this->assertSame(ProcessStatus::COMPLETED, (int) $process->ps_id);
        $this->assertNotNull($process->rp_file_save_path);

        $finalPath = storage_path('app/'.$process->rp_file_save_path);
        $this->assertFileExists($finalPath);
        $this->assertDirectoryDoesNotExist(storage_path('app/'.config('reports.subdir').'/tmp'));

        $body = file_get_contents($finalPath);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $body = substr($body, 3); // strip BOM
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body))));
        $this->assertSame('manufacturer_name,product_name,price,price_date', $lines[0]);
        $this->assertCount(1 + 3 * 2, $lines, 'header + 2 rows per product × 3 products');

        // Order preserved: chunks are concatenated in sorted-filename order,
        // which matches product_id order. fputcsv leaves plain ASCII unquoted.
        $this->assertStringContainsString(',Widget,10.00,', $lines[1]);
        $this->assertStringContainsString(',Widget,99.99,', $lines[2]);
        $this->assertStringContainsString(',Gadget,10.00,', $lines[3]);
        $this->assertStringContainsString(',Gizmo,99.99,', $lines[6]);
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

        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);
        Product::create([
            'product_name' => 'X',
            'category_id' => 21,
            'manufacturer_id' => $mfr->manufacturer_id,
        ]);

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
     * Mirror of the previous test for the failure branch: a batch with
     * failures must dispatch FinalizeReportJob with hadFailures=true,
     * which (see FinalizeReportJobTest) flips the row to Ошибка.
     */
    public function test_finally_callback_dispatches_finalize_when_batch_has_failures(): void
    {
        Bus::fake();

        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);
        Product::create([
            'product_name' => 'X',
            'category_id' => 30,
            'manufacturer_id' => $mfr->manufacturer_id,
        ]);

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
