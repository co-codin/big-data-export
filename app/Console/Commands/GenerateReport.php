<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeReportJob;
use App\Jobs\GenerateReportChunkJob;
use App\Models\ProcessStatus;
use App\Models\Product;
use App\Models\ReportProcess;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

class GenerateReport extends Command
{
    protected $signature = 'report:generate
        {category_id : Идентификатор категории товара}
        {--sync : Run all chunk jobs + finalize inline (no queue, useful for debugging)}';

    protected $description = 'Поставить в очередь Redis Bus-batch заданий на формирование CSV-отчёта (с чанками по продуктам).';

    public function handle(): int
    {
        $categoryId = (int) $this->argument('category_id');
        if ($categoryId <= 0) {
            $this->error('category_id должен быть положительным целым числом.');
            return self::INVALID;
        }

        $startedAt = Carbon::now();
        $startMicro = microtime(true);
        $pid = getmypid() ?: 0;

        $manufacturerIds = Product::where('category_id', $categoryId)
            ->distinct()
            ->orderBy('manufacturer_id')
            ->pluck('manufacturer_id');

        if ($manufacturerIds->isEmpty()) {
            // Spec: "при запуске процесса добавить запись". Even rejected attempts
            // are visible on the control page as an Ошибка row.
            ReportProcess::create([
                'rp_pid' => $pid,
                'rp_start_datetime' => $startedAt,
                'rp_exec_time' => (int) round((microtime(true) - $startMicro) * 1000),
                'ps_id' => ProcessStatus::ERROR,
            ]);
            $this->error("Ошибка: для категории {$categoryId} не найдено товаров.");
            return self::FAILURE;
        }
        $sync = (bool) $this->option('sync');
        $chunkSize = (int) config('reports.chunk_size', 5000);
        $subdir = config('reports.subdir', 'reports');
        $fromDate = $startedAt->copy()->subDays(7)->toDateString();
        $queueName = config('queue.connections.redis.queue', 'reports');

        foreach ($manufacturerIds as $manufacturerId) {
            // Memory-bounded boundary computation: walk products with chunkById
            // and record (first_id, last_id) of each window.
            $boundaries = [];
            Product::where('category_id', $categoryId)
                ->where('manufacturer_id', $manufacturerId)
                ->orderBy('product_id')
                ->chunkById($chunkSize, function ($products) use (&$boundaries) {
                    $boundaries[] = [
                        'start' => (int) $products->first()->product_id,
                        'end' => (int) $products->last()->product_id,
                    ];
                }, 'product_id', 'product_id');

            if (empty($boundaries)) {
                continue;
            }

            $process = ReportProcess::create([
                'rp_pid' => $pid,
                'rp_start_datetime' => $startedAt,
                'ps_id' => ProcessStatus::STARTED,
            ]);

            $rpId = (int) $process->rp_id;
            $tsPart = $startedAt->format('Y-m-d_H-i-s');
            $outputFileName = sprintf('report_%d_%d_%s.csv', $manufacturerId, $categoryId, $tsPart);
            $tmpRelativeDir = $subdir.'/tmp/'.$rpId;

            $chunkJobs = [];
            foreach ($boundaries as $idx => $range) {
                $chunkJobs[] = new GenerateReportChunkJob(
                    reportProcessId: $rpId,
                    manufacturerId: (int) $manufacturerId,
                    categoryId: $categoryId,
                    chunkIndex: $idx,
                    startProductId: $range['start'],
                    endProductId: $range['end'],
                    fromDate: $fromDate,
                    tmpRelativeDir: $tmpRelativeDir,
                );
            }

            $this->dispatchPipeline(
                chunkJobs: $chunkJobs,
                rpId: $rpId,
                tmpRelativeDir: $tmpRelativeDir,
                outputFileName: $outputFileName,
                queueName: $queueName,
                sync: $sync,
            );

            $verb = $sync ? 'выполнено синхронно' : 'поставлено в очередь';
            $this->info("rp_id={$rpId}: {$verb} (manufacturer={$manufacturerId}, чанков=".count($chunkJobs).')');
        }

        return self::SUCCESS;
    }

    /**
     * Sync path runs every chunk + finalize inline so behavior is deterministic
     * for tests and for debugging without the Redis worker.
     * Async path uses Bus::batch with a finally callback that fires the finalizer.
     */
    private function dispatchPipeline(
        array $chunkJobs,
        int $rpId,
        string $tmpRelativeDir,
        string $outputFileName,
        string $queueName,
        bool $sync,
    ): void {
        if ($sync) {
            foreach ($chunkJobs as $job) {
                $job->handle();
            }
            (new FinalizeReportJob(
                reportProcessId: $rpId,
                tmpRelativeDir: $tmpRelativeDir,
                outputFileName: $outputFileName,
                hadFailures: false,
            ))->handle();
            return;
        }

        Bus::batch($chunkJobs)
            ->name('report:'.$rpId)
            ->onQueue($queueName)
            ->finally(function (Batch $batch) use ($rpId, $tmpRelativeDir, $outputFileName, $queueName) {
                FinalizeReportJob::dispatch(
                    $rpId,
                    $tmpRelativeDir,
                    $outputFileName,
                    $batch->hasFailures(),
                )->onQueue($queueName);
            })
            ->dispatch();
    }
}
