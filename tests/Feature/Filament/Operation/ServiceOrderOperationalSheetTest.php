<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceOrderOperationalSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($this->company);
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->user->id]);
    }

    private function defect(string $report = 'Barulho na suspensão'): array
    {
        return ['report' => $report, 'diagnosis' => null, 'solution' => null, 'status' => 'pending'];
    }

    public function test_service_creates_sheet_and_rolls_back_invalid_defects(): void
    {
        $source = $this->order();
        $payload = $source->only(['company_id', 'customer_id', 'order_date', 'status', 'priority', 'type']);
        $service = app(ServiceOrderService::class);
        $created = $service->create($payload + ['number' => 'OS-FICHA', 'reported_by_name' => 'João', 'reported_by_role' => 'driver', 'entry_mileage' => 123456, 'defects' => [$this->defect()]], $this->user->id);
        $this->assertNotNull($created, $service->getMessage());
        $this->assertSame('João', $created->reported_by_name);
        $this->assertSame(123456, $created->entry_mileage);
        $this->assertSame($this->user->id, $created->defects()->sole()->created_by);

        $invalid = $service->create($payload + ['number' => 'OS-INVALIDA', 'defects' => [$this->defect(), $this->defect('')]], $this->user->id);
        $this->assertNull($invalid);
        $this->assertDatabaseMissing('service_orders', ['number' => 'OS-INVALIDA']);
    }

    public function test_admin_creation_action_and_edit_form_persist_the_sheet(): void
    {
        Filament::setCurrentPanel('admin');
        $customer = Partner::factory()->create();
        CompanyPartner::query()->create(['company_id' => $this->company->id, 'partner_id' => $customer->id, 'type' => ['customer'], 'is_active' => true]);
        $vehicle = Equipment::query()->create([
            'company_id' => $this->company->id, 'owner_id' => $customer->id,
            'name' => 'Caminhão', 'type' => EquipmentType::TRUCK,
            'placa' => 'ABC1D23', 'created_by' => $this->user->id,
        ]);
        Livewire::test(ListServiceOrders::class)
            ->callAction(TestAction::make('create')->table(), data: [
                'customer_id' => $customer->id, 'equipment_id' => $vehicle->id,
                'reported_by_name' => 'José', 'reported_by_role' => 'driver',
                'entry_mileage' => 150000, 'defects' => [$this->defect()],
            ])->assertHasNoActionErrors();
        $order = ServiceOrder::query()->sole();
        $this->assertSame($vehicle->id, $order->equipment_id);
        $this->assertSame('José', $order->reported_by_name);
        $defect = $order->defects()->sole();
        Livewire::test(EditServiceOrder::class, ['record' => $order->id])
            ->fillForm(['defects' => [['id' => $defect->id] + array_merge($this->defect(), ['diagnosis' => 'Bucha desgastada', 'status' => 'in_analysis'])]])
            ->call('save')->assertHasNoFormErrors()
            ->callAction('printOperationalSheet')->assertFileDownloaded('ficha-operacional-'.$order->number.'.pdf');
        $this->assertSame('Bucha desgastada', $defect->fresh()->diagnosis);
        $this->assertSame(1, $order->defects()->count());
    }

    public function test_mechanic_can_add_diagnose_resolve_and_remove_defects(): void
    {
        $order = $this->order();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->assertSee('Ficha operacional')
            ->call('addDefect')->set('formData.defects.0.report', 'Vazamento de óleo')
            ->set('formData.reported_by_name', 'Maria')->set('formData.reported_by_role', 'owner')
            ->set('formData.entry_mileage', 45000)->call('save')->assertHasNoErrors();
        $defect = $order->defects()->sole();
        $page->set('formData.defects.0.diagnosis', 'Junta danificada')
            ->set('formData.defects.0.solution', 'Junta substituída')
            ->set('formData.defects.0.status', 'resolved')->call('save')->assertHasNoErrors();
        $this->assertSame('resolved', $defect->fresh()->status);
        $this->assertSame('Junta substituída', $defect->fresh()->solution);
        $this->assertSame($this->user->id, $defect->fresh()->updated_by);
        $this->assertDatabaseHas('audit_entries', ['auditable_id' => $order->id, 'event' => 'service_order.operational_sheet.updated']);
        $page->call('removeDefect', 0)->call('save')->assertHasNoErrors();
        $this->assertSame(0, $order->defects()->count());
    }

    public function test_foreign_defect_ids_are_rejected_without_changing_sheet_or_order(): void
    {
        $order = $this->order();
        $own = $order->defects()->create($this->defect());
        $other = ServiceOrder::factory()->create();
        $foreign = $other->defects()->create($this->defect('Outro cliente'));
        $service = app(ServiceOrderService::class);
        $this->assertNull($service->update($order, ['reported_by_name' => 'Não salvar', 'defects' => [array_merge($this->defect(), ['id' => $foreign->id])]], $this->user->id));
        $this->assertNull($order->fresh()->reported_by_name);
        $this->assertNotNull($own->fresh());
        $this->assertSame('Outro cliente', $foreign->fresh()->report);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->set('formData.defects.0.id', $foreign->id)->call('save')->assertHasErrors('formData.defects.0.id');
        Livewire::test(ServiceOrderDetail::class, ['record' => $other->id])->call('printOperationalSheet')->assertStatus(404);
    }

    public function test_cancelled_and_invoiced_sheets_are_read_only_and_closed_sheets_can_be_updated(): void
    {
        $order = $this->order();
        $defect = $order->defects()->create($this->defect());
        foreach ([State::CANCELLED, State::INVOICED] as $state) {
            $order->update(['status' => $state]);
            Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
                ->assertSee('Barulho na suspensão')->assertDontSee('Adicionar defeito')
                ->call('addDefect')->assertStatus(403);
            $this->assertNull(app(ServiceOrderService::class)->update($order, ['defects' => []], $this->user->id));
            $this->assertNotNull($defect->fresh());
        }
        $order->update(['status' => State::CLOSED]);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->set('formData.defects.0.status', 'not_found')->call('save')->assertHasNoErrors();
        $this->assertSame('not_found', $defect->fresh()->status);
    }

    public function test_pdf_contains_operational_data_without_commercial_values(): void
    {
        $order = $this->order();
        $order->update(['reported_by_name' => 'Condutor da ficha', 'entry_mileage' => 120000, 'customer_observations' => 'Relato antigo', 'travel_value' => 98765]);
        $order->defects()->create($this->defect('<script>Barulho</script>'));
        $html = view('pdf.service-order-operational', ['record' => $order->load('defects')])->render();
        $this->assertStringContainsString('Condutor da ficha', $html);
        $this->assertStringContainsString('120.000 km', $html);
        $this->assertStringContainsString('Relato antigo', $html);
        $this->assertStringContainsString('&lt;script&gt;Barulho&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('98765', $html);
        $this->assertStringNotContainsString('R$', $html);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->call('printOperationalSheet')->assertFileDownloaded('ficha-operacional-'.$order->number.'.pdf');
    }
}
