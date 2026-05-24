<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_test_data_for_a_company_and_is_idempotent(): void
    {
        $arguments = [
            '--company' => 'Empresa Seed Teste',
            '--email' => 'seed-test@example.com',
            '--products' => 2,
            '--services' => 2,
            '--partners' => 3,
        ];

        $this->artisan('app:seed-test-data', $arguments)
            ->assertSuccessful();

        $this->artisan('app:seed-test-data', $arguments)
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'seed-test@example.com',
        ]);

        $this->assertDatabaseHas('companies', [
            'name' => 'Empresa Seed Teste',
            'email' => 'seed-test@example.com',
        ]);

        $companyId = (int) \App\Models\Company::query()
            ->where('name', 'Empresa Seed Teste')
            ->value('id');

        $this->assertDatabaseCount('company_user', 1);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('partners', 3);
        $this->assertDatabaseCount('company_partner', 3);
        $this->assertDatabaseCount('addresses', 3);
        $this->assertDatabaseCount('contacts', 3);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_taxes', 2);
        $this->assertDatabaseCount('product_stocks', 2);
        $this->assertDatabaseCount('services', 2);

        $this->assertDatabaseHas('products', [
            'company_id' => $companyId,
            'product_code' => sprintf('TESTE-%d-%03d', $companyId, 1),
        ]);

        $this->assertDatabaseHas('services', [
            'company_id' => $companyId,
            'service_code' => sprintf('SERV-%d-%03d', $companyId, 1),
        ]);
    }
}
