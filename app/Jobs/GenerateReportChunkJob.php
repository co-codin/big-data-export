<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
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
                ->chunkById(
                    self::READ_CHUNK,
                    fn (Collection $products) => $this->writePartialRows($fp, $products),
                    'product_id',
                    'product_id'
                );

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

    /**
     * For one chunkById batch: pull min+max prices in one DB round-trip pair
     * and write two CSV rows per product (min then max) to the partial file.
     *
     * @param  resource  $fp
     */
    private function writePartialRows($fp, Collection $products): void
    {
        $ids = $products->pluck('product_id')->all();
        if (empty($ids)) {
            return;
        }

        $minByPid = $this->distinctOnPrice('ASC', $ids);
        $maxByPid = $this->distinctOnPrice('DESC', $ids);

        foreach ($products as $product) {
            $min = $minByPid[$product->product_id] ?? null;
            $max = $maxByPid[$product->product_id] ?? null;
            if ($min === null || $max === null) {
                continue;
            }

            $manufacturerName = $product->manufacturer?->manufacturer_name ?? '';

            foreach ([$min, $max] as $row) {
                fputcsv($fp, [
                    $manufacturerName,
                    $product->product_name,
                    number_format((float) $row->price, 2, '.', ''),
                    $row->price_date,
                ], ',', '"', '\\');
            }
        }
    }

    /**
     * One `DISTINCT ON (product_id)` query — picks either the min or the max
     * price per product depending on `$direction` (`'ASC'` or `'DESC'`).
     * Returns rows indexed by product_id.
     *
     * @param  list<int>  $productIds
     * @return array<int,object>
     */
    private function distinctOnPrice(string $direction, array $productIds): array
    {
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("direction must be ASC or DESC, got: {$direction}");
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge([$this->fromDate], $productIds);

        $rows = DB::select(
            "SELECT DISTINCT ON (product_id) product_id, price, price_date
             FROM price
             WHERE price_date >= ? AND product_id IN ($placeholders)
             ORDER BY product_id, price {$direction}, price_date {$direction}",
            $params
        );

        $byPid = [];
        foreach ($rows as $row) {
            $byPid[$row->product_id] = $row;
        }

        return $byPid;
    }
}
