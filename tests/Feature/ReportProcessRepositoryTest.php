<?php

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use App\Repositories\ReportProcessRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportProcessRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ReportProcessRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->app->make(ReportProcessRepository::class);
    }

    public function test_list_for_control_page_orders_by_rp_id_descending(): void
    {
        $first = ReportProcess::factory()->errored()->create();
        $second = ReportProcess::factory()->errored()->create();
        $third = ReportProcess::factory()->errored()->create();

        $rows = $this->repo->listForControlPage();

        $this->assertSame(
            [$third->rp_id, $second->rp_id, $first->rp_id],
            $rows->pluck('rp_id')->all()
        );
    }

    public function test_list_for_control_page_filters_completed_rows_whose_file_is_missing(): void
    {
        // Three rows: one with a real file, one with a stale path, one with no path.
        $subdir = config('reports.subdir');
        $present = $subdir.'/present.csv';
        @mkdir(storage_path('app/'.$subdir), 0775, true);
        file_put_contents(storage_path('app/'.$present), "ok\n");

        $withFile = ReportProcess::factory()->completed($present)->create();
        $withoutFile = ReportProcess::factory()->completed($subdir.'/missing.csv')->create();
        $errored = ReportProcess::factory()->errored()->create();

        $rows = $this->repo->listForControlPage();

        $kept = $rows->pluck('rp_id')->all();
        $this->assertContains($withFile->rp_id, $kept);
        $this->assertContains($errored->rp_id, $kept, 'rows with no file path pass through');
        $this->assertNotContains($withoutFile->rp_id, $kept, 'orphan-path row is filtered');
    }

    public function test_find_or_fail_returns_the_row(): void
    {
        $process = ReportProcess::factory()->errored()->create();

        $found = $this->repo->findOrFail($process->rp_id);

        $this->assertSame($process->rp_id, $found->rp_id);
    }

    public function test_find_or_fail_throws_when_id_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repo->findOrFail(99999);
    }

    public function test_has_downloadable_file_covers_all_three_states(): void
    {
        $subdir = config('reports.subdir');
        $present = $subdir.'/here.csv';
        @mkdir(storage_path('app/'.$subdir), 0775, true);
        file_put_contents(storage_path('app/'.$present), "ok\n");

        $withFile = ReportProcess::factory()->completed($present)->create();
        $orphanPath = ReportProcess::factory()->completed($subdir.'/gone.csv')->create();
        $noPath = ReportProcess::factory()->errored()->create();

        $this->assertTrue($this->repo->hasDownloadableFile($withFile));
        $this->assertFalse($this->repo->hasDownloadableFile($orphanPath));
        $this->assertFalse($this->repo->hasDownloadableFile($noPath));
    }

    public function test_create_started_inserts_row_with_status_started(): void
    {
        $startedAt = Carbon::now();

        $process = $this->repo->createStarted(pid: 1234, startedAt: $startedAt);

        $this->assertSame(1234, $process->rp_pid);
        $this->assertSame(ProcessStatusId::Started, $process->ps_id);
        $this->assertNull($process->rp_exec_time);
        $this->assertNull($process->rp_file_save_path);
    }

    public function test_record_empty_attempt_inserts_row_with_status_error(): void
    {
        $startedAt = Carbon::now();

        $process = $this->repo->recordEmptyAttempt(pid: 5678, startedAt: $startedAt, execMs: 42);

        $this->assertSame(5678, $process->rp_pid);
        $this->assertSame(ProcessStatusId::Error, $process->ps_id);
        $this->assertSame(42, $process->rp_exec_time);
        $this->assertNull($process->rp_file_save_path);
    }
}
