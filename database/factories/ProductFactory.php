<?php

namespace Database\Factories;

use App\Models\Manufacturer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'product_name' => fake()->words(2, true),
            'category_id' => fake()->numberBetween(1, 100),
            'manufacturer_id' => Manufacturer::factory(),
        ];
    }

    public function inCategory(int $categoryId): static
    {
        return $this->state(fn () => ['category_id' => $categoryId]);
    }
}
