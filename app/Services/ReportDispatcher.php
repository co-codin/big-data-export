<?php

namespace App\Services;

use App\Data\DispatchedReport;
use App\Data\DispatchResult;
use App\Jobs\FinalizeReportJob;
use App\Jobs\GenerateReportChunkJob;
use App\Models\Product;
use App\Repositories\ReportProcessRepository;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * The single home for "given a category_id, fan out the work that produces
 * the CSV reports". Triggers (CLI command today, HTTP endpoint or scheduler
 * tomorrow) call dispatchForCategory() and read the DispatchResult to
 * decide how to talk back to their user.
 */
class ReportDispatcher
{
    private readonly int $chunkSize;

    private readonly string $subdir;

    private readonly string $queueName;

    public function __construct(
        private readonly ReportProcessRepository $repo,
        ?int $chunkSize = null,
        ?string $subdir = null,
        ?string $queueName = null,
    ) {
        $resolved = $chunkSize ?? (int) config('reports.chunk_size', 5000);
        if ($resolved <= 0) {
            throw new \InvalidArgumentException(
                "reports.chunk_size must be a positive integer; got {$resolved}."
            );
        }
        $this->chunkSize = $resolved;
        $this->subdir = $subdir ?? config('reports.subdir', 'reports');
        $this->queueName = $queueName ?? config('queue.connections.redis.queue', 'reports');
    }

    public function dispatchForCategory(int $categoryId): DispatchResult
    {
        $startedAt = Carbon::now();
        $startMicro = microtime(true);
        $pid = getmypid() ?: 0;
        $fromDate = $startedAt->copy()->subDays(7)->toDateString();

        $manufacturerIds = $this->manufacturersInCategory($categoryId);

        if ($manufacturerIds->isEmpty()) {
            $this->repo->recordEmptyAttempt(
                $pid,
                $startedAt,
                (int) round((microtime(true) - $startMicro) * 1000),
            );

            return new DispatchResult(emptyCategory: true, reports: []);
        }

        $reports = [];
        foreach ($manufacturerIds as $manufacturerId) {
            $entry = $this->dispatchForManufacturer(
                (int) $manufacturerId,
                $categoryId,
                $pid,
                $startedAt,
                $fromDate,
            );
            if ($entry !== null) {
                $reports[] = $entry;
            }
        }

        return new DispatchResult(emptyCategory: false, reports: $reports);
    }

    private function manufacturersInCategory(int $categoryId): Collection
    {
        return Product::where('category_id', $categoryId)
            ->distinct()
            ->orderBy('manufacturer_id')
            ->pluck('manufacturer_id');
    }

    private function dispatchForManufacturer(
        int $manufacturerId,
        int $categoryId,
        int $pid,
        Carbon $startedAt,
        string $fromDate,
    ): ?DispatchedReport {
        $boundaries = $this->chunkBoundariesFor($manufacturerId, $categoryId);
        if (empty($boundaries)) {
            return null;
        }

        $process = $this->repo->createStarted($pid, $startedAt);
        $rpId = (int) $process->rp_id;
        $outputFileName = $this->outputFileName($manufacturerId, $categoryId, $startedAt);
        $tmpRelativeDir = $this->subdir.'/tmp/'.$rpId;

        $chunkJobs = $this->buildChunkJobs(
            $rpId,
            $manufacturerId,
            $categoryId,
            $boundaries,
            $fromDate,
            $tmpRelativeDir,
        );

        $this->dispatchAsBatch($chunkJobs, $rpId, $tmpRelativeDir, $outputFileName);

        return new DispatchedReport(
            rpId: $rpId,
            manufacturerId: $manufacturerId,
            chunkCount: count($chunkJobs),
        );
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

    private function outputFileName(int $manufacturerId, int $categoryId, Carbon $startedAt): string
    {
        return sprintf(
            'report_%d_%d_%s.csv',
            $manufacturerId,
            $categoryId,
            $startedAt->format('Y-m-d_H-i-s')
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
        string $fromDate,
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
                fromDate: $fromDate,
                tmpRelativeDir: $tmpRelativeDir,
            );
        }

        return $jobs;
    }

    /**
     * Bus::batch fans the chunk jobs out across workers, then the finally
     * callback fires the finalizer with whatever the batch reports —
     * hasFailures() is true if any chunk failed (or the batch was cancelled),
     * false on clean completion. The finalizer is responsible for flipping
     * the report_process row to Завершен / Ошибка either way.
     *
     * @param  list<GenerateReportChunkJob>  $chunkJobs
     */
    private function dispatchAsBatch(
        array $chunkJobs,
        int $rpId,
        string $tmpRelativeDir,
        string $outputFileName,
    ): void {
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
