<?php

namespace App\Jobs;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs after all GenerateReportChunkJob siblings have finished.
 * Concatenates the partial files into one final CSV (with BOM + header),
 * updates the report_process row, and removes the tmp directory.
 */
class FinalizeReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $reportProcessId,
        public readonly string $tmpRelativeDir,
        public readonly string $outputFileName,
        public readonly bool $hadFailures = false,
    ) {
        $this->onQueue(config('queue.connections.redis.queue', 'reports'));
    }

    public function handle(): void
    {
        try {
            $process = ReportProcess::find($this->reportProcessId);
            if ($process === null) {
                Log::warning('FinalizeReportJob: report_process not found', [
                    'rp_id' => $this->reportProcessId,
                ]);

                return;
            }

            if ($this->hadFailures) {
                $process->update([
                    'ps_id' => ProcessStatusId::Error,
                    'rp_exec_time' => $this->wallClockMs($process),
                ]);
                Log::error('FinalizeReportJob: batch had failures, marking process as Ошибка', [
                    'rp_id' => $this->reportProcessId,
                ]);

                return;
            }

            $this->writeFinalCsv($process);
        } finally {
            $this->cleanupTmp();
        }
    }

    private function writeFinalCsv(ReportProcess $process): void
    {
        $subdir = config('reports.subdir', 'reports');
        $relativeOutput = $subdir.'/'.$this->outputFileName;
        $absoluteOutput = storage_path('app/'.$relativeOutput);

        @mkdir(dirname($absoluteOutput), 0775, true);

        $out = fopen($absoluteOutput, 'w');
        if ($out === false) {
            $this->markAsError($process);
            Log::error('FinalizeReportJob: failed to open output', [
                'rp_id' => $this->reportProcessId,
                'path' => $absoluteOutput,
            ]);
            throw new \RuntimeException("Не удалось открыть итоговый файл: {$absoluteOutput}");
        }

        try {
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['manufacturer_name', 'product_name', 'price', 'price_date'], ',', '"', '\\');

            $parts = glob(storage_path('app/'.$this->tmpRelativeDir).'/part*.csv') ?: [];
            sort($parts); // part00000.csv → part00001.csv → ...

            foreach ($parts as $part) {
                $in = fopen($part, 'r');
                if ($in === false) {
                    throw new \RuntimeException("Не удалось прочитать часть: {$part}");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fflush($out);
        } catch (Throwable $e) {
            fclose($out);
            @unlink($absoluteOutput);
            $this->markAsError($process);
            Log::error('FinalizeReportJob: failed', [
                'rp_id' => $this->reportProcessId,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        fclose($out);

        $process->update([
            'ps_id' => ProcessStatusId::Completed,
            'rp_exec_time' => $this->wallClockMs($process),
            'rp_file_save_path' => $relativeOutput,
        ]);

        Log::info('FinalizeReportJob: completed', [
            'rp_id' => $this->reportProcessId,
            'parts' => count($parts),
            'path' => $relativeOutput,
        ]);
    }

    private function markAsError(ReportProcess $process): void
    {
        $process->update([
            'ps_id' => ProcessStatusId::Error,
            'rp_exec_time' => $this->wallClockMs($process),
        ]);
    }

    /**
     * Total wall-clock time from when the producer command created the
     * report_process row to "now" (finalize complete), in milliseconds.
     * Carries no information about queue-wait vs. work time, but matches
     * what a user would expect "execution time" to mean.
     */
    private function wallClockMs(ReportProcess $process): int
    {
        $start = $process->rp_start_datetime ?? Carbon::now();

        return (int) round(Carbon::now()->diffInMilliseconds($start));
    }

    private function cleanupTmp(): void
    {
        $absoluteTmpDir = storage_path('app/'.$this->tmpRelativeDir);
        if (! is_dir($absoluteTmpDir)) {
            return;
        }
        foreach (glob($absoluteTmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($absoluteTmpDir);
        // Best-effort: also remove the shared parent `tmp/` if it ended up empty.
        @rmdir(dirname($absoluteTmpDir));
    }
}
