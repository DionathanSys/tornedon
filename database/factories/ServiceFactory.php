<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'service_code' => $this->faker->unique()->bothify('SRV-####'),
            'name' => $this->faker->sentence(3),
            'price' => $this->faker->randomFloat(2, 50, 5000),
            'min_sale_price' => null,
            'accept_customer_discount' => false,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
