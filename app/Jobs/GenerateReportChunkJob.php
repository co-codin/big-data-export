<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One slice of products for a single (manufacturer, category) report.
 * Writes a partial CSV (no header, no BOM) under storage/app/{tmpRelativeDir}/.
 * The finalizer concatenates these in order to produce the final file.
 */
class GenerateReportChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    private const READ_CHUNK = 1000;

    public function __construct(
        public readonly int $reportProcessId,
        public readonly int $manufacturerId,
        public readonly int $categoryId,
        public readonly int $chunkIndex,
        public readonly int $startProductId,
        public readonly int $endProductId,
        public readonly string $fromDate,
        public readonly string $tmpRelativeDir,
    ) {
        $this->onQueue(config('queue.connections.redis.queue', 'reports'));
    }

    public function handle(): void
    {
        // If the batch was cancelled (e.g. an earlier chunk failed and we
        // cancelled-on-failure), skip the work but produce nothing.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $partFile = sprintf('%s/part%05d.csv', $this->tmpRelativeDir, $this->chunkIndex);
        $absolutePath = storage_path('app/'.$partFile);
        @mkdir(dirname($absolutePath), 0775, true);

        $fp = fopen($absolutePath, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Не удалось открыть часть отчёта: {$absolutePath}");
        }

        try {
            Product::query()
                ->with('manufacturer:manufacturer_id,manufacturer_name')
                ->where('category_id', $this->categoryId)
                ->where('manufacturer_id', $this->manufacturerId)
                ->whereBetween('product_id', [$this->startProductId, $this->endProductId])
                ->orderBy('product_id')
                ->chunkById(self::READ_CHUNK, function ($products) use ($fp) {
                    $ids = $products->pluck('product_id')->all();
                    if (empty($ids)) {
                        return;
                    }
                    [$minByPid, $maxByPid] = $this->minMaxForProducts($ids);

                    foreach ($products as $product) {
                        $min = $minByPid[$product->product_id] ?? null;
                        $max = $maxByPid[$product->product_id] ?? null;
                        if ($min === null || $max === null) {
                            continue;
                        }
                        $mfrName = $product->manufacturer?->manufacturer_name ?? '';

                        fputcsv($fp, [
                            $mfrName,
                            $product->product_name,
                            number_format((float) $min->price, 2, '.', ''),
                            $min->price_date,
                        ], ',', '"', '\\');

                        fputcsv($fp, [
                            $mfrName,
                            $product->product_name,
                            number_format((float) $max->price, 2, '.', ''),
                            $max->price_date,
                        ], ',', '"', '\\');
                    }
                }, 'product_id', 'product_id');

            fflush($fp);
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($absolutePath);
            Log::error('GenerateReportChunkJob: failed', [
                'rp_id' => $this->reportProcessId,
                'chunk_index' => $this->chunkIndex,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        fclose($fp);
    }

    /** Per-chunk DB-side aggregation: two DISTINCT ON queries, no PHP-side N+1. */
    private function minMaxForProducts(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge([$this->fromDate], $productIds);

        $minRows = DB::select(
            "SELECT DISTINCT ON (product_id) product_id, price, price_date
             FROM price
             WHERE price_date >= ? AND product_id IN ($placeholders)
             ORDER BY product_id, price ASC, price_date ASC",
            $params
        );

        $maxRows = DB::select(
            "SELECT DISTINCT ON (product_id) product_id, price, price_date
             FROM price
             WHERE price_date >= ? AND product_id IN ($placeholders)
             ORDER BY product_id, price DESC, price_date DESC",
            $params
        );

        $minByPid = [];
        foreach ($minRows as $row) {
            $minByPid[$row->product_id] = $row;
        }
        $maxByPid = [];
        foreach ($maxRows as $row) {
            $maxByPid[$row->product_id] = $row;
        }

        return [$minByPid, $maxByPid];
    }
}
