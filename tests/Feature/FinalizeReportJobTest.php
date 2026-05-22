<?php

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Jobs\FinalizeReportJob;
use App\Models\ReportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FinalizeReportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_concatenates_parts_with_bom_and_header_then_cleans_up(): void
    {
        $process = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$process->rp_id;
        $absTmp = storage_path('app/'.$tmpDir);

        @mkdir($absTmp, 0775, true);
        file_put_contents($absTmp.'/part00000.csv', "\"Acme\",\"A\",1.00,2026-05-15\n\"Acme\",\"A\",2.00,2026-05-19\n");
        file_put_contents($absTmp.'/part00001.csv', "\"Acme\",\"B\",3.00,2026-05-15\n\"Acme\",\"B\",4.00,2026-05-19\n");

        (new FinalizeReportJob(
            reportProcessId: $process->rp_id,
            tmpRelativeDir: $tmpDir,
            outputFileName: 'merged.csv',
            hadFailures: false,
        ))->handle();

        $process->refresh();
        $this->assertSame(ProcessStatusId::Completed, $process->ps_id);
        $this->assertSame(config('reports.subdir').'/merged.csv', $process->rp_file_save_path);
        $this->assertGreaterThanOrEqual(0, $process->rp_exec_time, 'rp_exec_time must not be negative');

        $finalPath = storage_path('app/'.$process->rp_file_save_path);
        $body = file_get_contents($finalPath);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('manufacturer_name,product_name,price,price_date', $body);
        $this->assertStringContainsString('"Acme","A",1.00,2026-05-15', $body);
        $this->assertStringContainsString('"Acme","B",4.00,2026-05-19', $body);

        $this->assertDirectoryDoesNotExist($absTmp, 'tmp dir must be wiped after finalize');
    }

    public function test_concatenates_parts_in_sorted_order(): void
    {
        $process = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$process->rp_id;
        $absTmp = storage_path('app/'.$tmpDir);
        @mkdir($absTmp, 0775, true);

        // Write parts in reverse — finalize should still sort them.
        file_put_contents($absTmp.'/part00002.csv', "\"M\",\"Z3\",0,2026-01-01\n");
        file_put_contents($absTmp.'/part00000.csv', "\"M\",\"Z1\",0,2026-01-01\n");
        file_put_contents($absTmp.'/part00001.csv', "\"M\",\"Z2\",0,2026-01-01\n");

        (new FinalizeReportJob(
            reportProcessId: $process->rp_id,
            tmpRelativeDir: $tmpDir,
            outputFileName: 'ordered.csv',
            hadFailures: false,
        ))->handle();

        $process->refresh();
        $body = file_get_contents(storage_path('app/'.$process->rp_file_save_path));
        $body = substr($body, 3); // strip BOM
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body))));

        // After header, the order should be Z1, Z2, Z3
        $this->assertStringContainsString('"Z1"', $lines[1]);
        $this->assertStringContainsString('"Z2"', $lines[2]);
        $this->assertStringContainsString('"Z3"', $lines[3]);
    }

    public function test_flips_row_to_error_when_batch_had_failures(): void
    {
        Log::spy();

        $process = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$process->rp_id;
        @mkdir(storage_path('app/'.$tmpDir), 0775, true);
        file_put_contents(storage_path('app/'.$tmpDir.'/part00000.csv'), "x,y,1,2026-01-01\n");

        (new FinalizeReportJob(
            reportProcessId: $process->rp_id,
            tmpRelativeDir: $tmpDir,
            outputFileName: 'shouldnotexist.csv',
            hadFailures: true,
        ))->handle();

        $process->refresh();
        $this->assertSame(ProcessStatusId::Error, $process->ps_id);
        $this->assertNull($process->rp_file_save_path);
        $this->assertDirectoryDoesNotExist(storage_path('app/'.$tmpDir));
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_missing_report_process_logs_warning_and_still_cleans_up(): void
    {
        Log::spy();
        $tmpDir = config('reports.subdir').'/tmp/9999999';
        @mkdir(storage_path('app/'.$tmpDir), 0775, true);
        file_put_contents(storage_path('app/'.$tmpDir.'/part00000.csv'), "x\n");

        (new FinalizeReportJob(
            reportProcessId: 9999999,
            tmpRelativeDir: $tmpDir,
            outputFileName: 'nope.csv',
            hadFailures: false,
        ))->handle();

        $this->assertDirectoryDoesNotExist(storage_path('app/'.$tmpDir));
        Log::shouldHaveReceived('warning')->once();
    }

    private function makeProcess(): ReportProcess
    {
        return ReportProcess::factory()->started()->create();
    }
}
