<?php

namespace Database\Seeders;

use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: re-running on an already-populated database is a no-op.
        if (Manufacturer::query()->exists()) {
            return;
        }

        // Manufacturers
        $acme = Manufacturer::create(['manufacturer_name' => 'ACME Corp']);
        $globex = Manufacturer::create(['manufacturer_name' => 'Globex Inc.']);
        $initech = Manufacturer::create(['manufacturer_name' => 'Initech Ltd.']);

        // Category 1: Electronics (ACME + Globex)
        $laptop = Product::create([
            'product_name' => 'Laptop Pro 14',
            'category_id' => 1,
            'manufacturer_id' => $acme->manufacturer_id,
        ]);
        $phone = Product::create([
            'product_name' => 'SmartPhone X',
            'category_id' => 1,
            'manufacturer_id' => $acme->manufacturer_id,
        ]);
        $tablet = Product::create([
            'product_name' => 'TabletAir 10',
            'category_id' => 1,
            'manufacturer_id' => $globex->manufacturer_id,
        ]);

        // Category 2: Furniture (Initech only)
        $chair = Product::create([
            'product_name' => 'Ergo Chair',
            'category_id' => 2,
            'manufacturer_id' => $initech->manufacturer_id,
        ]);
        $desk = Product::create([
            'product_name' => 'Standing Desk',
            'category_id' => 2,
            'manufacturer_id' => $initech->manufacturer_id,
        ]);

        // Category 3: empty — used to demo the "no products" error path.

        // Seed daily prices for each product over the last 10 days. The report
        // only looks at the last 7, but having extra days proves the filter works.
        $today = Carbon::today();
        $products = [
            [$laptop, 1200.00, 50.0],
            [$phone,  800.00,  30.0],
            [$tablet, 500.00,  20.0],
            [$chair,  250.00,  10.0],
            [$desk,   600.00,  25.0],
        ];

        foreach ($products as [$product, $base, $jitter]) {
            for ($daysAgo = 0; $daysAgo < 10; $daysAgo++) {
                $delta = (($daysAgo * 37) % 7 - 3) * $jitter / 3;
                Price::create([
                    'product_id' => $product->product_id,
                    'price' => round($base + $delta, 2),
                    'price_date' => $today->copy()->subDays($daysAgo)->toDateString(),
                ]);
            }
        }
    }
}
