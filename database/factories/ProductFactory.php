<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Company;
use App\Models\User;
use App\Enum\Product\Unit;
use App\Enum\Product\OriginSalePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_code' => $this->faker->unique()->bothify('PRD-####'),
            'name' => $this->faker->word,
            'unit' => Unit::UN,
            'origin_sale_price' => OriginSalePrice::FREE,
            'sale_price_value' => $this->faker->randomFloat(2, 10, 1000),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
