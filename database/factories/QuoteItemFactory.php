<?php

namespace Database\Factories;

use App\Models\QuoteItem;
use App\Models\Quote;
use App\Models\Product;
use App\Enum\Quote\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'product_id' => Product::factory(),
            'description' => $this->faker->sentence,
            'unit_of_measure' => 'UN',
            'quantity' => $this->faker->numberBetween(1, 10),
            'unit_price' => $this->faker->randomFloat(2, 10, 500),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'status' => Status::DRAFT,
        ];
    }
}
