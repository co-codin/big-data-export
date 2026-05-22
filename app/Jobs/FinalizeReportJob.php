<?php

namespace App\Jobs;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $process = ReportProcess::find($this->reportProcessId);
        if ($process === null) {
            Log::warning('FinalizeReportJob: report_process not found', [
                'rp_id' => $this->reportProcessId,
            ]);
            $this->cleanupTmp();
            return;
        }

        $startMicro = microtime(true);

        if ($this->hadFailures) {
            $this->cleanupTmp();
            $process->update([
                'ps_id' => ProcessStatusId::Error,
                'rp_exec_time' => $this->execMsFrom($startMicro, $process),
            ]);
            Log::error('FinalizeReportJob: batch had failures, marking process as Ошибка', [
                'rp_id' => $this->reportProcessId,
            ]);
            return;
        }

        $subdir = config('reports.subdir', 'reports');
        $relativeOutput = $subdir.'/'.$this->outputFileName;
        $absoluteOutput = storage_path('app/'.$relativeOutput);
        $absoluteTmpDir = storage_path('app/'.$this->tmpRelativeDir);

        @mkdir(dirname($absoluteOutput), 0775, true);

        $out = null;
        try {
            $out = fopen($absoluteOutput, 'w');
            if ($out === false) {
                throw new \RuntimeException("Не удалось открыть итоговый файл: {$absoluteOutput}");
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['manufacturer_name', 'product_name', 'price', 'price_date'], ',', '"', '\\');

            $parts = glob($absoluteTmpDir.'/part*.csv') ?: [];
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
            fclose($out);
            $out = null;

            $process->update([
                'ps_id' => ProcessStatusId::Completed,
                'rp_exec_time' => $this->execMsFrom($startMicro, $process),
                'rp_file_save_path' => $relativeOutput,
            ]);

            $this->cleanupTmp();

            Log::info('FinalizeReportJob: completed', [
                'rp_id' => $this->reportProcessId,
                'parts' => count($parts),
                'path' => $relativeOutput,
            ]);
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($absoluteOutput);
            $this->cleanupTmp();

            $process->update([
                'ps_id' => ProcessStatusId::Error,
                'rp_exec_time' => $this->execMsFrom($startMicro, $process),
            ]);
            Log::error('FinalizeReportJob: failed', [
                'rp_id' => $this->reportProcessId,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function execMsFrom(float $startMicro, ReportProcess $process): int
    {
        // Include the time already attributed to the chunk-dispatch step.
        $finalizeMs = (int) round((microtime(true) - $startMicro) * 1000);
        return (int) ($process->rp_exec_time ?? 0) + $finalizeMs;
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
