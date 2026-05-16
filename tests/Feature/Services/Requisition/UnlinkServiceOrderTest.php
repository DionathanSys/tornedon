<?php

namespace Tests\Feature\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Requisition\RequisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlinkServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_unlinks_requisition_when_both_documents_are_open(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();

        $service = app(RequisitionService::class);
        $result = $service->unlinkServiceOrder($requisition, $user->id);

        $this->assertNotNull($result, $service->getMessage());
        $this->assertNull($result->service_order_id);
        $this->assertNull($requisition->fresh()->service_order_id);
    }

    public function test_it_blocks_unlink_when_requisition_is_not_open(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $requisition->update(['status' => Status::CLOSED]);

        $service = app(RequisitionService::class);
        $result = $service->unlinkServiceOrder($requisition->fresh(), $user->id);

        $this->assertNull($result);
        $this->assertSame('Só é possível desvincular requisições abertas.', $service->getMessage());
        $this->assertSame($serviceOrder->id, $requisition->fresh()->service_order_id);
    }

    public function test_it_blocks_unlink_when_service_order_is_not_open(): void
    {
        [$user, $serviceOrder, $requisition] = $this->makeLinkedDocuments();
        $serviceOrder->update(['status' => State::CLOSED]);

        $service = app(RequisitionService::class);
        $result = $service->unlinkServiceOrder($requisition->fresh(), $user->id);

        $this->assertNull($result);
        $this->assertSame('Só é possível desvincular quando a ordem de serviço vinculada estiver aberta.', $service->getMessage());
        $this->assertSame($serviceOrder->id, $requisition->fresh()->service_order_id);
    }

    private function makeLinkedDocuments(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Desvinculo',
            'document_number' => '11122233000144',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Desvinculo',
            'document_type' => 'CPF',
            'document_number' => '12345678910',
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-UNLINK-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-UNLINK-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'service_order_id' => $serviceOrder->id,
            'sale_date' => now()->toDateString(),
            'status' => Status::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $serviceOrder, $requisition];
    }
}
