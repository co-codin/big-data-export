<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportChunkJob;
use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\ProcessStatus;
use App\Models\Product;
use App\Models\ReportProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerateReportChunkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_partial_csv_with_no_header_or_bom(): void
    {
        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);
        $product = Product::create([
            'product_name' => 'Widget',
            'category_id' => 11,
            'manufacturer_id' => $mfr->manufacturer_id,
        ]);
        Price::create([
            'product_id' => $product->product_id,
            'price' => 100.00,
            'price_date' => Carbon::today()->subDays(6),
        ]);
        Price::create([
            'product_id' => $product->product_id,
            'price' => 250.55,
            'price_date' => Carbon::today()->subDays(2),
        ]);

        $rp = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$rp->rp_id;

        $job = new GenerateReportChunkJob(
            reportProcessId: $rp->rp_id,
            manufacturerId: $mfr->manufacturer_id,
            categoryId: 11,
            chunkIndex: 0,
            startProductId: $product->product_id,
            endProductId: $product->product_id,
            fromDate: Carbon::today()->subDays(7)->toDateString(),
            tmpRelativeDir: $tmpDir,
        );

        $job->handle();

        $partPath = storage_path('app/'.$tmpDir.'/part00000.csv');
        $this->assertFileExists($partPath);

        $body = file_get_contents($partPath);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $body, 'partial must NOT have BOM');
        $this->assertStringNotContainsString('manufacturer_name,product_name', $body, 'partial must NOT have header');

        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', '\\'), array_values(array_filter(preg_split('/\r?\n/', trim($body)))));
        $this->assertCount(2, $rows, 'two rows: min and max for one product');
        $this->assertSame(['Acme', 'Widget', '100.00', Carbon::today()->subDays(6)->toDateString()], $rows[0]);
        $this->assertSame(['Acme', 'Widget', '250.55', Carbon::today()->subDays(2)->toDateString()], $rows[1]);
    }

    public function test_only_includes_products_in_id_range(): void
    {
        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);
        $p1 = Product::create(['product_name' => 'P1', 'category_id' => 12, 'manufacturer_id' => $mfr->manufacturer_id]);
        $p2 = Product::create(['product_name' => 'P2', 'category_id' => 12, 'manufacturer_id' => $mfr->manufacturer_id]);
        $p3 = Product::create(['product_name' => 'P3', 'category_id' => 12, 'manufacturer_id' => $mfr->manufacturer_id]);

        foreach ([$p1, $p2, $p3] as $p) {
            Price::create(['product_id' => $p->product_id, 'price' => 10, 'price_date' => Carbon::today()]);
        }

        $rp = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$rp->rp_id;

        // Range covers only p2.
        $job = new GenerateReportChunkJob(
            reportProcessId: $rp->rp_id,
            manufacturerId: $mfr->manufacturer_id,
            categoryId: 12,
            chunkIndex: 3,
            startProductId: $p2->product_id,
            endProductId: $p2->product_id,
            fromDate: Carbon::today()->subDays(7)->toDateString(),
            tmpRelativeDir: $tmpDir,
        );
        $job->handle();

        $body = file_get_contents(storage_path('app/'.$tmpDir.'/part00003.csv'));
        // fputcsv leaves simple ASCII tokens unquoted, so match on cell boundaries.
        $this->assertStringContainsString(',P2,', $body);
        $this->assertStringNotContainsString(',P1,', $body);
        $this->assertStringNotContainsString(',P3,', $body);
    }

    public function test_produces_empty_partial_when_no_prices_in_window(): void
    {
        $mfr = Manufacturer::create(['manufacturer_name' => 'Acme']);
        $product = Product::create(['product_name' => 'Old', 'category_id' => 13, 'manufacturer_id' => $mfr->manufacturer_id]);
        Price::create(['product_id' => $product->product_id, 'price' => 50, 'price_date' => Carbon::today()->subDays(30)]);

        $rp = $this->makeProcess();
        $tmpDir = config('reports.subdir').'/tmp/'.$rp->rp_id;

        (new GenerateReportChunkJob(
            reportProcessId: $rp->rp_id,
            manufacturerId: $mfr->manufacturer_id,
            categoryId: 13,
            chunkIndex: 0,
            startProductId: $product->product_id,
            endProductId: $product->product_id,
            fromDate: Carbon::today()->subDays(7)->toDateString(),
            tmpRelativeDir: $tmpDir,
        ))->handle();

        $partPath = storage_path('app/'.$tmpDir.'/part00000.csv');
        $this->assertFileExists($partPath);
        $this->assertSame('', file_get_contents($partPath));
    }

    private function makeProcess(): ReportProcess
    {
        return ReportProcess::create([
            'rp_pid' => 0,
            'rp_start_datetime' => Carbon::now(),
            'ps_id' => ProcessStatus::STARTED,
        ]);
    }
}
