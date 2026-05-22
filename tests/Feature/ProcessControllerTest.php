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
        $ok = ReportProcess::factory()->completed('reports_test/some.csv')->create();
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
