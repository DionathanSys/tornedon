<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\Partner\Type as PartnerType;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status as WarrantyClaimStatus;
use App\Enum\WarrantyClaim\Type as WarrantyClaimType;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\ServiceOrder\ServiceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_transfers_open_service_order_and_linked_requisition_reusing_existing_equipment(): void
    {
        [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer] = $this->makeTransferContext();

        $matchingEquipment = Equipment::query()->create([
            'company_id' => $serviceOrder->company_id,
            'owner_id' => $targetCustomer->id,
            'name' => $sourceEquipment->name,
            'type' => $sourceEquipment->type,
            'serial_number' => $sourceEquipment->serial_number,
            'mark' => 'Outra marca',
            'model' => 'Outro modelo',
            'created_by' => $user->id,
        ]);

        $warrantyClaim = WarrantyClaim::query()->create([
            'company_id' => $serviceOrder->company_id,
            'number' => 'GAR-OS-001',
            'type' => WarrantyClaimType::SERVICE_COMPANY,
            'status' => WarrantyClaimStatus::DRAFT,
            'customer_id' => $serviceOrder->customer_id,
            'origin_service_order_id' => $serviceOrder->id,
            'equipment_id' => $sourceEquipment->id,
            'quantity' => 1,
            'coverage_type' => CoverageType::LABOR,
            'responsibility' => Responsibility::COMPANY,
            'customer_issue_description' => 'Garantia aberta antes da transferência.',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $originalCustomerId = $serviceOrder->customer_id;

        $service = app(ServiceOrderService::class);
        $transferred = $service->transfer($serviceOrder, $targetCustomer->id, null, $user->id);

        $this->assertNotNull($transferred, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame($targetCustomer->id, $serviceOrder->fresh()->customer_id);
        $this->assertSame($matchingEquipment->id, $serviceOrder->fresh()->equipment_id);
        $this->assertSame($targetCustomer->id, $requisition->fresh()->customer_id);
        $this->assertSame($matchingEquipment->id, $requisition->fresh()->equipment_id);
        $this->assertSame($originalCustomerId, $warrantyClaim->fresh()->customer_id);
        $this->assertSame($sourceEquipment->id, $warrantyClaim->fresh()->equipment_id);
        $this->assertCount(2, Equipment::query()->where('company_id', $serviceOrder->company_id)->get());
    }

    public function test_it_creates_new_equipment_when_target_customer_has_no_matching_equipment(): void
    {
        [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer] = $this->makeTransferContext();

        $service = app(ServiceOrderService::class);
        $transferred = $service->transfer($serviceOrder, $targetCustomer->id, null, $user->id);

        $this->assertNotNull($transferred, $service->getMessage());
        $this->assertFalse($service->hasError());

        $newEquipment = Equipment::query()
            ->where('company_id', $serviceOrder->company_id)
            ->where('owner_id', $targetCustomer->id)
            ->first();

        $this->assertNotNull($newEquipment);
        $this->assertNotSame($sourceEquipment->id, $newEquipment->id);
        $this->assertSame($sourceEquipment->name, $newEquipment->name);
        $this->assertSame($sourceEquipment->serial_number, $newEquipment->serial_number);
        $this->assertSame($sourceEquipment->type, $newEquipment->type);
        $this->assertSame($targetCustomer->id, $serviceOrder->fresh()->customer_id);
        $this->assertSame($newEquipment->id, $serviceOrder->fresh()->equipment_id);
        $this->assertSame($targetCustomer->id, $requisition->fresh()->customer_id);
        $this->assertSame($newEquipment->id, $requisition->fresh()->equipment_id);
    }

    public function test_it_transfers_closed_service_order_and_closed_requisition(): void
    {
        [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer] = $this->makeTransferContext(
            serviceOrderStatus: State::CLOSED,
            requisitionStatus: RequisitionStatus::CLOSED,
        );

        $targetEquipment = Equipment::query()->create([
            'company_id' => $serviceOrder->company_id,
            'owner_id' => $targetCustomer->id,
            'name' => 'Equipamento Cliente Destino',
            'type' => EquipmentType::OTHER,
            'serial_number' => 'EQ-DEST-001',
            'created_by' => $user->id,
        ]);

        $service = app(ServiceOrderService::class);
        $transferred = $service->transfer($serviceOrder, $targetCustomer->id, $targetEquipment->id, $user->id);

        $this->assertNotNull($transferred, $service->getMessage());
        $this->assertFalse($service->hasError());
        $this->assertSame(State::CLOSED, $serviceOrder->fresh()->status);
        $this->assertSame(RequisitionStatus::CLOSED, $requisition->fresh()->status);
        $this->assertSame($targetCustomer->id, $serviceOrder->fresh()->customer_id);
        $this->assertSame($targetEquipment->id, $serviceOrder->fresh()->equipment_id);
        $this->assertSame($targetCustomer->id, $requisition->fresh()->customer_id);
        $this->assertSame($targetEquipment->id, $requisition->fresh()->equipment_id);
    }

    public function test_it_blocks_transfer_for_non_transferable_service_order_statuses(): void
    {
        foreach ([State::INVOICED, State::CANCELLED] as $status) {
            [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer] = $this->makeTransferContext(
                serviceOrderStatus: $status,
                requisitionStatus: RequisitionStatus::OPEN,
            );

            $service = app(ServiceOrderService::class);
            $transferred = $service->transfer($serviceOrder, $targetCustomer->id, null, $user->id);

            $this->assertNull($transferred);
            $this->assertTrue($service->hasError());
            $this->assertSame('Só é possível transferir ordens de serviço abertas ou encerradas.', $service->getMessage());
            $this->assertSame($sourceEquipment->id, $serviceOrder->fresh()->equipment_id);
            $this->assertSame($requisition->getOriginal('customer_id'), $requisition->fresh()->customer_id);
        }
    }

    public function test_it_blocks_transfer_when_linked_requisition_is_not_transferable(): void
    {
        foreach ([RequisitionStatus::INVOICED, RequisitionStatus::CANCELLED] as $status) {
            [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer] = $this->makeTransferContext(
                serviceOrderStatus: State::OPEN,
                requisitionStatus: $status,
            );

            $service = app(ServiceOrderService::class);
            $transferred = $service->transfer($serviceOrder, $targetCustomer->id, null, $user->id);

            $this->assertNull($transferred);
            $this->assertTrue($service->hasError());
            $this->assertSame('Não é possível transferir a ordem de serviço porque a requisição vinculada está faturada ou cancelada.', $service->getMessage());
            $this->assertSame($sourceEquipment->id, $serviceOrder->fresh()->equipment_id);
            $this->assertSame($requisition->getOriginal('customer_id'), $requisition->fresh()->customer_id);
        }
    }

    private function makeTransferContext(
        State $serviceOrderStatus = State::OPEN,
        RequisitionStatus $requisitionStatus = RequisitionStatus::OPEN,
    ): array {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Transferência OS '.fake()->unique()->numerify('###'),
            'document_number' => fake()->numerify('########0001##'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $sourceCustomer = Partner::query()->create([
            'name' => 'Cliente Origem',
            'document_type' => 'CPF',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);

        $targetCustomer = Partner::query()->create([
            'name' => 'Cliente Destino',
            'document_type' => 'CPF',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);

        foreach ([$sourceCustomer, $targetCustomer] as $customer) {
            CompanyPartner::query()->create([
                'company_id' => $company->id,
                'partner_id' => $customer->id,
                'type' => [PartnerType::CUSTOMER->value],
                'invoice_threshold' => 0,
                'customer_discount_percentage' => 0,
                'is_active' => true,
            ]);
        }

        $sourceEquipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $sourceCustomer->id,
            'name' => 'Equipamento Origem',
            'type' => EquipmentType::OTHER,
            'serial_number' => 'EQ-TRANS-001',
            'mark' => 'Marca X',
            'model' => 'Modelo Y',
            'created_by' => $user->id,
        ]);

        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-TRANS-001',
            'customer_id' => $sourceCustomer->id,
            'company_id' => $company->id,
            'equipment_id' => $sourceEquipment->id,
            'order_date' => now()->toDateString(),
            'status' => $serviceOrderStatus,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $requisition = Requisition::query()->create([
            'number' => 'REQ-TRANS-001',
            'customer_id' => $sourceCustomer->id,
            'company_id' => $company->id,
            'service_order_id' => $serviceOrder->id,
            'equipment_id' => $sourceEquipment->id,
            'sale_date' => now()->toDateString(),
            'status' => $requisitionStatus,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $serviceOrder, $requisition, $sourceEquipment, $targetCustomer];
    }
}
