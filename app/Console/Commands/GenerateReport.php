<?php

namespace App\Console\Commands;

use App\Enums\ProcessStatusId;
use App\Jobs\FinalizeReportJob;
use App\Jobs\GenerateReportChunkJob;
use App\Models\Product;
use App\Models\ReportProcess;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class GenerateReport extends Command
{
    protected $signature = 'report:generate
        {category_id : Идентификатор категории товара}
        {--sync : Run all chunk jobs + finalize inline (no queue, useful for debugging)}';

    protected $description = 'Поставить в очередь Redis Bus-batch заданий на формирование CSV-отчёта (с чанками по продуктам).';

    private Carbon $startedAt;

    private float $startMicro;

    private int $pid;

    private int $chunkSize;

    private string $subdir;

    private string $fromDate;

    private string $queueName;

    public function handle(): int
    {
        $categoryId = $this->parseCategoryId();
        if ($categoryId === null) {
            return self::INVALID;
        }

        $this->initContext();

        $manufacturerIds = $this->manufacturersInCategory($categoryId);

        if ($manufacturerIds->isEmpty()) {
            $this->recordEmptyCategoryAttempt();
            $this->error("Ошибка: для категории {$categoryId} не найдено товаров.");

            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        foreach ($manufacturerIds as $manufacturerId) {
            $this->dispatchForManufacturer((int) $manufacturerId, $categoryId, $sync);
        }

        return self::SUCCESS;
    }

    private function parseCategoryId(): ?int
    {
        $id = (int) $this->argument('category_id');
        if ($id <= 0) {
            $this->error('category_id должен быть положительным целым числом.');

            return null;
        }

        return $id;
    }

    /**
     * Snapshot all per-run state once so the rest of the methods don't have
     * to re-read config or re-stamp clocks. Throws if reports.chunk_size is
     * misconfigured (would silently break chunkById downstream otherwise).
     */
    private function initContext(): void
    {
        $this->startedAt = Carbon::now();
        $this->startMicro = microtime(true);
        $this->pid = getmypid() ?: 0;

        $this->chunkSize = (int) config('reports.chunk_size', 5000);
        if ($this->chunkSize <= 0) {
            throw new \InvalidArgumentException(
                "reports.chunk_size must be a positive integer; got {$this->chunkSize}."
            );
        }

        $this->subdir = config('reports.subdir', 'reports');
        $this->fromDate = $this->startedAt->copy()->subDays(7)->toDateString();
        $this->queueName = config('queue.connections.redis.queue', 'reports');
    }

    private function manufacturersInCategory(int $categoryId): Collection
    {
        return Product::where('category_id', $categoryId)
            ->distinct()
            ->orderBy('manufacturer_id')
            ->pluck('manufacturer_id');
    }

    /**
     * Spec: "при запуске процесса добавить запись". Even rejected attempts
     * land on the control page as an Ошибка row so the operator sees them.
     */
    private function recordEmptyCategoryAttempt(): void
    {
        ReportProcess::create([
            'rp_pid' => $this->pid,
            'rp_start_datetime' => $this->startedAt,
            'rp_exec_time' => (int) round((microtime(true) - $this->startMicro) * 1000),
            'ps_id' => ProcessStatusId::Error,
        ]);
    }

    private function dispatchForManufacturer(int $manufacturerId, int $categoryId, bool $sync): void
    {
        $boundaries = $this->chunkBoundariesFor($manufacturerId, $categoryId);
        if (empty($boundaries)) {
            return;
        }

        $process = $this->createStartedProcess();
        $rpId = (int) $process->rp_id;
        $outputFileName = $this->outputFileName($manufacturerId, $categoryId);
        $tmpRelativeDir = $this->subdir.'/tmp/'.$rpId;

        $chunkJobs = $this->buildChunkJobs($rpId, $manufacturerId, $categoryId, $boundaries, $tmpRelativeDir);

        $this->dispatchPipeline($chunkJobs, $rpId, $tmpRelativeDir, $outputFileName, $sync);

        $verb = $sync ? 'выполнено синхронно' : 'поставлено в очередь';
        $this->info("rp_id={$rpId}: {$verb} (manufacturer={$manufacturerId}, чанков=".count($chunkJobs).')');
    }

    /**
     * Memory-bounded boundary computation: walk products with chunkById and
     * record (first_id, last_id) of each window. Never materializes the full
     * id list, which matters for manufacturers with millions of products.
     *
     * @return list<array{start:int,end:int}>
     */
    private function chunkBoundariesFor(int $manufacturerId, int $categoryId): array
    {
        $boundaries = [];
        Product::where('category_id', $categoryId)
            ->where('manufacturer_id', $manufacturerId)
            ->orderBy('product_id')
            ->chunkById($this->chunkSize, function ($products) use (&$boundaries) {
                $boundaries[] = [
                    'start' => (int) $products->first()->product_id,
                    'end' => (int) $products->last()->product_id,
                ];
            }, 'product_id', 'product_id');

        return $boundaries;
    }

    private function createStartedProcess(): ReportProcess
    {
        return ReportProcess::create([
            'rp_pid' => $this->pid,
            'rp_start_datetime' => $this->startedAt,
            'ps_id' => ProcessStatusId::Started,
        ]);
    }

    private function outputFileName(int $manufacturerId, int $categoryId): string
    {
        return sprintf(
            'report_%d_%d_%s.csv',
            $manufacturerId,
            $categoryId,
            $this->startedAt->format('Y-m-d_H-i-s')
        );
    }

    /**
     * @param  list<array{start:int,end:int}>  $boundaries
     * @return list<GenerateReportChunkJob>
     */
    private function buildChunkJobs(
        int $rpId,
        int $manufacturerId,
        int $categoryId,
        array $boundaries,
        string $tmpRelativeDir,
    ): array {
        $jobs = [];
        foreach ($boundaries as $idx => $range) {
            $jobs[] = new GenerateReportChunkJob(
                reportProcessId: $rpId,
                manufacturerId: $manufacturerId,
                categoryId: $categoryId,
                chunkIndex: $idx,
                startProductId: $range['start'],
                endProductId: $range['end'],
                fromDate: $this->fromDate,
                tmpRelativeDir: $tmpRelativeDir,
            );
        }

        return $jobs;
    }

    /**
     * Sync path runs every chunk + finalize inline so behavior is deterministic
     * for tests and for debugging without the Redis worker.
     * Async path uses Bus::batch with a finally callback that fires the finalizer.
     *
     * @param  list<GenerateReportChunkJob>  $chunkJobs
     */
    private function dispatchPipeline(
        array $chunkJobs,
        int $rpId,
        string $tmpRelativeDir,
        string $outputFileName,
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

        $queueName = $this->queueName;
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
