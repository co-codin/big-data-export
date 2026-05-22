<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportChunkJob;
use App\Models\Manufacturer;
use App\Models\Price;
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
        $mfr = Manufacturer::factory()->create(['manufacturer_name' => 'Acme']);
        $product = Product::factory()
            ->for($mfr)
            ->create(['product_name' => 'Widget', 'category_id' => 11]);

        Price::factory()->for($product)->create([
            'price' => 100.00,
            'price_date' => Carbon::today()->subDays(6),
        ]);
        Price::factory()->for($product)->create([
            'price' => 250.55,
            'price_date' => Carbon::today()->subDays(2),
        ]);

        $rp = ReportProcess::factory()->started()->create();
        $tmpDir = config('reports.subdir').'/tmp/'.$rp->rp_id;

        (new GenerateReportChunkJob(
            reportProcessId: $rp->rp_id,
            manufacturerId: $mfr->manufacturer_id,
            categoryId: 11,
            chunkIndex: 0,
            startProductId: $product->product_id,
            endProductId: $product->product_id,
            fromDate: Carbon::today()->subDays(7)->toDateString(),
            tmpRelativeDir: $tmpDir,
        ))->handle();

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
        $mfr = Manufacturer::factory()->create();

        $products = collect(['P1', 'P2', 'P3'])->map(fn ($name) => Product::factory()
            ->for($mfr)
            ->create(['product_name' => $name, 'category_id' => 12]));

        $products->each(fn ($p) => Price::factory()->for($p)->create([
            'price' => 10,
            'price_date' => Carbon::today(),
        ]));

        $rp = ReportProcess::factory()->started()->create();
        $tmpDir = config('reports.subdir').'/tmp/'.$rp->rp_id;

        // Range covers only the middle product (P2).
        (new GenerateReportChunkJob(
            reportProcessId: $rp->rp_id,
            manufacturerId: $mfr->manufacturer_id,
            categoryId: 12,
            chunkIndex: 3,
            startProductId: $products[1]->product_id,
            endProductId: $products[1]->product_id,
            fromDate: Carbon::today()->subDays(7)->toDateString(),
            tmpRelativeDir: $tmpDir,
        ))->handle();

        $body = file_get_contents(storage_path('app/'.$tmpDir.'/part00003.csv'));
        // fputcsv leaves simple ASCII tokens unquoted, so match on cell boundaries.
        $this->assertStringContainsString(',P2,', $body);
        $this->assertStringNotContainsString(',P1,', $body);
        $this->assertStringNotContainsString(',P3,', $body);
    }

    public function test_produces_empty_partial_when_no_prices_in_window(): void
    {
        $mfr = Manufacturer::factory()->create();
        $product = Product::factory()->for($mfr)->create(['category_id' => 13]);
        Price::factory()->for($product)->create([
            'price' => 50,
            'price_date' => Carbon::today()->subDays(30),
        ]);

        $rp = ReportProcess::factory()->started()->create();
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
}
