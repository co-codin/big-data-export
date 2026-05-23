<?php

namespace Tests\Feature;

use App\Models\ReportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_processes_and_highlights_errors(): void
    {
        // Create a real file for the completed row — the controller filters
        // out completed rows whose file is missing on disk.
        $relPath = config('reports.subdir').'/some.csv';
        $absPath = storage_path('app/'.$relPath);
        @mkdir(dirname($absPath), 0775, true);
        file_put_contents($absPath, "ok\n");

        $ok = ReportProcess::factory()->completed($relPath)->create();
        $broken = ReportProcess::factory()->errored()->create();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeText('Контроль выполнения процессов');
        $response->assertSeeText('Завершен');
        $response->assertSeeText('Ошибка');
        $response->assertSee('class="status-error"', false);

        // Completed row has a download link; error row does not.
        $response->assertSee(route('processes.download', ['id' => $ok->rp_id]), false);
        $response->assertDontSee(route('processes.download', ['id' => $broken->rp_id]), false);
    }

    public function test_index_filters_out_completed_rows_whose_file_was_deleted(): void
    {
        // One completed row whose file actually exists on disk.
        $relPath = config('reports.subdir').'/still-here.csv';
        $absPath = storage_path('app/'.$relPath);
        @mkdir(dirname($absPath), 0775, true);
        file_put_contents($absPath, "ok\n");
        $present = ReportProcess::factory()->completed($relPath)->create();

        // Another completed row whose file is NOT on disk.
        $deleted = ReportProcess::factory()
            ->completed(config('reports.subdir').'/gone.csv')
            ->create();

        // An Ошибка row with no path at all — should pass through, has no file.
        $errored = ReportProcess::factory()->errored()->create();

        $response = $this->get('/');
        $response->assertOk();

        // The present-file row is kept (download link visible).
        $response->assertSee(route('processes.download', ['id' => $present->rp_id]), false);
        // The orphan-path row is filtered out — no download link rendered.
        $response->assertDontSee(route('processes.download', ['id' => $deleted->rp_id]), false);

        // 2 <tr> rows rendered: $present + $errored. The orphan ($deleted) is gone.
        $rendered = substr_count($response->getContent(), '<tr class=');
        $this->assertSame(2, $rendered, 'orphan-path row must be filtered');
        // Suppress unused-var warning while keeping the row creation meaningful.
        $this->assertNotNull($errored->rp_id);
    }

    public function test_download_returns_csv_for_completed_process(): void
    {
        $subdir = config('reports.subdir');
        $relPath = $subdir.'/example.csv';
        $absPath = storage_path('app/'.$relPath);
        @mkdir(dirname($absPath), 0775, true);
        file_put_contents($absPath, "\xEF\xBB\xBFmanufacturer_name,product_name,price,price_date\n");

        $process = ReportProcess::factory()->completed($relPath)->create();

        $response = $this->get(route('processes.download', ['id' => $process->rp_id]));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=example.csv');
    }

    public function test_download_returns_404_for_unknown_rp_id(): void
    {
        $this->get(route('processes.download', ['id' => 9999]))->assertNotFound();
    }

    public function test_download_returns_404_when_file_is_missing_on_disk(): void
    {
        $process = ReportProcess::factory()
            ->completed('reports_test/nope.csv')
            ->create();

        $this->get(route('processes.download', ['id' => $process->rp_id]))->assertNotFound();
    }

    public function test_download_returns_404_when_no_file_path_stored(): void
    {
        $process = ReportProcess::factory()->errored()->create();

        $this->get(route('processes.download', ['id' => $process->rp_id]))->assertNotFound();
    }
}
