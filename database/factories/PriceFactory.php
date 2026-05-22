<?php

namespace Database\Factories;

use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    protected $model = Price::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price' => fake()->randomFloat(2, 1, 10_000),
            'price_date' => Carbon::today()->subDays(fake()->numberBetween(0, 6))->toDateString(),
        ];
    }

    public function on(string|Carbon $date): static
    {
        $resolved = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this->state(fn () => ['price_date' => $resolved]);
    }

    public function priced(float $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }
}
