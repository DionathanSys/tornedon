<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Enum\Quote\Status;
use App\Enum\Payment\Method;
use App\Enum\Payment\Condition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_id' => Partner::factory(),
            'description' => $this->faker->sentence,
            'status' => Status::DRAFT,
            'payment_method' => Method::PIX,
            'payment_condition' => Condition::CASH,
            'valid_until' => now()->addDays(30),
            'created_by' => User::factory(),
        ];
    }
}
