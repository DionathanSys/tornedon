<?php

namespace Tests\Feature\Services\ProductionOrder;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\ProductionOrder\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderDestinationTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_stock_destination_when_customer_is_not_informed(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany($user);

        $productionOrder = app(ProductionOrderService::class)->create([
            'company_id' => $company->id,
            'priority' => Priority::NORMAL->value,
            'observations' => 'Sem cliente informado.',
        ], $user->id);

        $this->assertNotNull($productionOrder);
        $this->assertNull($productionOrder->customer_id);
        $this->assertSame(DestinationType::STOCK, $productionOrder->destination_type);
    }

    public function test_it_creates_direct_delivery_destination_when_customer_is_informed(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany($user);
        $customer = $this->makeCustomer($user);

        $productionOrder = app(ProductionOrderService::class)->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'priority' => Priority::NORMAL->value,
            'observations' => 'Com cliente informado.',
        ], $user->id);

        $this->assertNotNull($productionOrder);
        $this->assertSame($customer->id, $productionOrder->customer_id);
        $this->assertSame(DestinationType::DIRECT_DELIVERY, $productionOrder->destination_type);
    }

    private function makeCompany(User $user): Company
    {
        return Company::query()->create([
            'name' => 'Empresa Producao',
            'document_number' => '55444333000122',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function makeCustomer(User $user): Partner
    {
        return Partner::query()->create([
            'name' => 'Cliente Producao',
            'document_type' => 'CPF',
            'document_number' => '98765432100',
            'created_by' => $user->id,
        ]);
    }
}
