<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Operation\Pages\OperationDashboard;
use App\Filament\Operation\Pages\Requisitions\RequisitionDetail;
use App\Filament\Operation\Pages\Requisitions\RequisitionList;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue;
use App\Livewire\OperationMenu;
use App\Models\Company;
use App\Models\Partner;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OperationPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_dashboard_is_accessible_and_has_only_mvp_shortcuts(): void
    {
        [$user, $company] = $this->authenticateTenant();

        $this->assertTrue($user->fresh()->canAccessTenant($company));

        $url = OperationDashboard::getUrl(['tenant' => $company]);

        $this->assertStringContainsString('/operation/', $url);

        $response = $this->get($url);

        $response
            ->assertOk()
            ->assertSee('Nova OS')
            ->assertSee('Nova Requisição')
            ->assertSee('Menu')
            ->assertSee('Mudar empresa')
            ->assertSee('Nova ordem')
            ->assertSee('Nova requisição')
            ->assertSee('Acesso rápido')
            ->assertSee('Ordens de Serviço')
            ->assertSee('Requisições')
            ->assertDontSee('Equipamentos');

        Livewire::test(OperationDashboard::class)
            ->assertActionExists('createServiceOrder')
            ->assertActionExists('createRequisition');
    }

    public function test_operation_dashboard_allows_switching_between_companies(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $otherCompany = $this->createCompany($user, 'Outra Empresa');

        $user->companies()->attach($otherCompany, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->get(OperationDashboard::getUrl(tenant: $company));

        $response->assertOk();

        Livewire::test(OperationMenu::class)
            ->callAction('switchTenant', data: [
                'tenant_id' => $otherCompany->getKey(),
            ])
            ->assertRedirect(Filament::getUrl($otherCompany));

        Livewire::test(OperationMenu::class)
            ->assertActionExists('switchTenant')
            ->assertActionExists('createServiceOrder')
            ->assertActionExists('createRequisition');
    }

    public function test_operation_lists_expose_their_create_actions(): void
    {
        [, $company] = $this->authenticateTenant();

        Livewire::test(ServiceOrderQueue::class)
            ->assertActionExists('createServiceOrder')
            ->assertActionVisible('createServiceOrder');

        $this->get(ServiceOrderQueue::getUrl(tenant: $company))
            ->assertOk()
            ->assertSee('Nova OS');

        Livewire::test(RequisitionList::class)
            ->assertActionExists('createRequisition')
            ->assertActionVisible('createRequisition');

        $this->get(RequisitionList::getUrl(tenant: $company))
            ->assertOk()
            ->assertSee('Nova Requisição');
    }

    public function test_operation_create_actions_use_the_current_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $customer = $this->createCustomer($user, 'Cliente Operação');

        Livewire::test(ServiceOrderQueue::class)
            ->callAction('createServiceOrder', data: [
                'customer_id' => $customer->id,
            ])
            ->assertHasNoActionErrors();

        $serviceOrder = ServiceOrder::query()->latest('id')->firstOrFail();

        $this->assertSame($company->id, $serviceOrder->company_id);
        $this->assertSame(State::OPEN, $serviceOrder->status);

        Livewire::test(RequisitionList::class)
            ->callAction('createRequisition', data: [
                'customer_id' => $customer->id,
            ])
            ->assertHasNoActionErrors();

        $requisition = Requisition::query()->latest('id')->firstOrFail();

        $this->assertSame($company->id, $requisition->company_id);
        $this->assertSame(RequisitionStatus::OPEN, $requisition->status);
    }

    public function test_service_order_queue_is_scoped_to_the_current_tenant(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $otherCompany = $this->createCompany($user, 'Outra Empresa');

        $this->createServiceOrder($user, $company, 'OS-OP-A');
        $otherOrder = $this->createServiceOrder($user, $otherCompany, 'OS-OP-B');

        Livewire::test(ServiceOrderQueue::class)
            ->assertSee('OS-OP-A')
            ->assertDontSee($otherOrder->number);
    }

    public function test_service_order_detail_updates_through_the_service_layer(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $order = $this->createServiceOrder($user, $company, 'OS-OP-SAVE');

        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->set('formData.solution', 'Solução registrada pela operação')
            ->set('formData.technician_observations', 'Teste de observação')
            ->call('save');

        $this->assertSame('Solução registrada pela operação', $order->fresh()->solution);
        $this->assertSame('Teste de observação', $order->fresh()->technician_observations);
    }

    public function test_service_order_detail_cannot_access_another_tenant_record(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $otherCompany = $this->createCompany($user, 'Outra Empresa');
        $otherOrder = $this->createServiceOrder($user, $otherCompany, 'OS-OP-HIDDEN');

        Livewire::test(ServiceOrderDetail::class, ['record' => $otherOrder->id])
            ->assertSet('order', null)
            ->assertSee('Ordem de serviço não encontrada.');
    }

    public function test_requisition_detail_can_cancel_an_open_requisition(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $customer = $this->createCustomer($user, 'Cliente Requisição');
        $requisition = Requisition::query()->create([
            'number' => 'REQ-OP-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'sale_date' => today()->toDateString(),
            'status' => RequisitionStatus::OPEN,
            'stock_reserved' => false,
            'stock_consumed' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Livewire::test(RequisitionDetail::class, ['record' => $requisition->id])
            ->call('cancel');

        $this->assertSame(RequisitionStatus::CANCELLED, $requisition->fresh()->status);
    }

    /**
     * @return array{User, Company}
     */
    private function authenticateTenant(): array
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, 'Empresa Operação');

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Auth::setUser($user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function createCompany(User $user, string $name): Company
    {
        return Company::query()->create([
            'name' => $name.' '.Str::uuid(),
            'document_number' => fake()->numerify('########000199'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    private function createCustomer(User $user, string $name): Partner
    {
        return Partner::query()->create([
            'name' => $name,
            'document_type' => 'CPF',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);
    }

    private function createServiceOrder(User $user, Company $company, string $number): ServiceOrder
    {
        $customer = $this->createCustomer($user, 'Cliente '.$number);

        return ServiceOrder::query()->create([
            'number' => $number,
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => today()->toDateString(),
            'status' => State::OPEN,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'technician_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
