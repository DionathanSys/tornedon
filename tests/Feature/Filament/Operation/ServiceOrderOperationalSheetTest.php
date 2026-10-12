<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Equipment\Type as EquipmentType;
use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Support\ServiceOrderOperationalSheet;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
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
        CompanyEntitlement::query()->create(['company_id' => $this->company->id, 'feature' => ServiceOrderOperationalSheet::FEATURE, 'enabled' => true]);
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['company_id' => $this->company->id, 'created_by' => $this->user->id]);
    }

    private function defect(string $report = 'Barulho na suspensão'): array
    {
        return ['report' => $report];
    }

    private function equipment(ServiceOrder $order, EquipmentType $type = EquipmentType::CAR): Equipment
    {
        return Equipment::query()->create([
            'company_id' => $order->company_id, 'owner_id' => $order->customer_id,
            'name' => 'Equipamento de teste', 'type' => $type, 'placa' => 'ABC1D23',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_service_creates_sheet_and_rolls_back_invalid_defects(): void
    {
        $source = $this->order();
        $vehicle = $this->equipment($source);
        $payload = $source->only(['company_id', 'customer_id', 'order_date', 'status', 'priority', 'type']);
        $service = app(ServiceOrderService::class);
        $created = $service->create($payload + ['number' => 'OS-FICHA', 'equipment_id' => $vehicle->id, 'entry_mileage' => 123456, 'defects' => [$this->defect()]], $this->user->id);
        $this->assertNotNull($created, $service->getMessage());
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
                'entry_mileage' => 150000, 'defects' => [$this->defect()],
            ])->assertHasNoActionErrors();
        $order = ServiceOrder::query()->sole();
        $this->assertSame($vehicle->id, $order->equipment_id);
        $this->assertSame(150000, $order->entry_mileage);
        $defect = $order->defects()->sole();
        Livewire::test(EditServiceOrder::class, ['record' => $order->id])
            ->fillForm(['defects' => [['id' => $defect->id] + $this->defect('Barulho ao frear')]])
            ->call('save')->assertHasNoFormErrors()
            ->callAction('printOperationalSheet')->assertFileDownloaded('ficha-operacional-'.$order->number.'.pdf');
        $this->assertSame('Barulho ao frear', $defect->fresh()->report);
        $this->assertSame(1, $order->defects()->count());
    }

    public function test_mechanic_can_add_edit_and_remove_defects_using_only_description(): void
    {
        $order = $this->order();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->assertSee('Defeitos relatados')->assertDontSee('Nome de quem relatou')->assertDontSee('Diagnóstico técnico')
            ->call('addDefect')->set('formData.defects.0.report', 'Vazamento de óleo')
            ->call('save')->assertHasNoErrors()->assertSee('Defeito citado');
        $defect = $order->defects()->sole();
        $page->set('formData.defects.0.report', 'Vazamento de óleo com motor quente')->call('save')->assertHasNoErrors();
        $this->assertSame('Vazamento de óleo com motor quente', $defect->fresh()->report);
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
        $this->assertNull($service->update($order, ['general_observations' => 'Não salvar', 'defects' => [array_merge($this->defect(), ['id' => $foreign->id])]], $this->user->id));
        $this->assertNull($order->fresh()->general_observations);
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
            ->set('formData.defects.0.report', 'Barulho ao ligar')->call('save')->assertHasNoErrors();
        $this->assertSame('Barulho ao ligar', $defect->fresh()->report);
    }

    public function test_pdf_contains_operational_data_without_commercial_values(): void
    {
        $order = $this->order();
        $order->update(['equipment_id' => $this->equipment($order)->id]);
        $order->update(['reported_by_name' => 'Condutor da ficha', 'entry_mileage' => 120000, 'customer_observations' => 'Relato antigo', 'travel_value' => 98765]);
        $order->defects()->create($this->defect('<script>Barulho</script>'));
        $html = view('pdf.service-order-operational', ['record' => $order->load('defects')])->render();
        $this->assertStringNotContainsString('Condutor da ficha', $html);
        $this->assertStringNotContainsString('Diagnóstico técnico', $html);
        $this->assertStringContainsString('120.000 km', $html);
        $this->assertStringContainsString('Relato antigo', $html);
        $this->assertStringContainsString('&lt;script&gt;Barulho&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('98765', $html);
        $this->assertStringNotContainsString('R$', $html);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->call('printOperationalSheet')->assertFileDownloaded('ficha-operacional-'.$order->number.'.pdf');
    }

    public function test_disabled_feature_hides_sheet_and_blocks_direct_calls_but_normal_order_saving_works(): void
    {
        CompanyEntitlement::query()->delete();
        $order = $this->order();
        $defect = $order->defects()->create($this->defect('Relato reservado'));
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->assertDontSee('Defeitos relatados')->assertDontSee('Relato reservado')->assertDontSee('Imprimir ficha operacional');
        $this->assertArrayNotHasKey('defects', $page->get('formData'));
        $this->assertArrayNotHasKey('defects', $page->get('order'));
        $page->set('formData.general_observations', 'Edição normal')->call('save')->assertHasNoErrors();
        $this->assertSame('Edição normal', $order->fresh()->general_observations);
        $this->assertNotNull($defect->fresh());
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->call('addDefect')->assertStatus(403);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->call('removeDefect', 0)->assertStatus(403);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->call('printOperationalSheet')->assertStatus(403);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->set('formData.defects', [])->call('save')->assertStatus(403);
        $service = app(ServiceOrderService::class);
        $this->assertNull($service->update($order, ['defects' => []], $this->user->id));
        $this->assertNotNull($defect->fresh());
        Filament::setCurrentPanel('admin');
        Livewire::test(EditServiceOrder::class, ['record' => $order->id])
            ->assertDontSee('Defeitos relatados')->assertActionHidden('printOperationalSheet')
            ->fillForm(['general_observations' => 'Edição administrativa'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Edição administrativa', $order->fresh()->general_observations);
        $this->assertNotNull($defect->fresh());
    }

    public function test_creation_without_entitlement_works_normally_and_rejects_operational_payloads(): void
    {
        CompanyEntitlement::query()->delete();
        Filament::setCurrentPanel('admin');
        $customer = Partner::factory()->create();
        CompanyPartner::query()->create(['company_id' => $this->company->id, 'partner_id' => $customer->id, 'type' => ['customer'], 'is_active' => true]);
        Livewire::test(ListServiceOrders::class)
            ->callAction(TestAction::make('create')->table(), data: ['customer_id' => $customer->id])
            ->assertHasNoActionErrors();
        $created = ServiceOrder::query()->sole();
        $this->assertSame(0, $created->defects()->count());
        $payload = $created->only(['company_id', 'customer_id', 'order_date', 'status', 'priority', 'type']);
        $service = app(ServiceOrderService::class);
        $this->assertNull($service->create($payload + ['number' => 'OS-SEM-FLAG', 'defects' => [$this->defect()]], $this->user->id));
        $this->assertDatabaseMissing('service_orders', ['number' => 'OS-SEM-FLAG']);
        $this->assertNull($service->update($created, ['entry_mileage' => 100], $this->user->id));
    }

    public function test_flag_respects_company_and_active_dates_and_is_checked_again_after_revocation(): void
    {
        $entitlement = CompanyEntitlement::query()->sole();
        $order = $this->order();
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id]);
        $entitlement->update(['enabled' => false]);
        $page->call('addDefect')->assertStatus(403);
        $entitlement->update(['enabled' => true, 'starts_at' => now()->addDay()]);
        $this->assertFalse(ServiceOrderOperationalSheet::enabled($this->company->id));
        $entitlement->update(['starts_at' => null, 'ends_at' => now()->subMinute()]);
        $this->assertFalse(ServiceOrderOperationalSheet::enabled($this->company->id));
        $entitlement->update(['ends_at' => null]);
        $this->assertTrue(ServiceOrderOperationalSheet::enabled($this->company->id));
        $other = Company::factory()->create();
        $this->assertFalse(ServiceOrderOperationalSheet::enabled($other->id));
    }

    public function test_mileage_is_shown_after_equipment_only_for_cars_and_trucks(): void
    {
        $order = $this->order();
        $car = $this->equipment($order);
        $truck = $this->equipment($order, EquipmentType::TRUCK);
        $other = $this->equipment($order, EquipmentType::OTHER);
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])->assertDontSee('Quilometragem na entrada');
        $page->set('formData.equipment_id', $car->id)->assertSee('Quilometragem na entrada')
            ->set('formData.entry_mileage', 45000)->call('save')->assertHasNoErrors();
        $this->assertSame(45000, $order->fresh()->entry_mileage);
        $page->set('formData.equipment_id', $truck->id)->assertSee('Quilometragem na entrada')
            ->set('formData.entry_mileage', 80000)->call('save')->assertHasNoErrors();
        $this->assertSame(80000, $order->fresh()->entry_mileage);
        $page->set('formData.equipment_id', $other->id)->assertDontSee('Quilometragem na entrada')
            ->set('formData.entry_mileage', 100)->call('save')->assertHasErrors('formData.entry_mileage');
        $this->assertNull(app(ServiceOrderService::class)->update($order, ['equipment_id' => $other->id, 'entry_mileage' => 100], $this->user->id));
        Filament::setCurrentPanel('admin');
        $admin = Livewire::test(EditServiceOrder::class, ['record' => $order->id])->assertFormFieldVisible('entry_mileage');
        $admin->fillForm(['equipment_id' => $other->id])->assertFormFieldHidden('entry_mileage');
        $admin->fillForm(['equipment_id' => $car->id])->assertFormFieldVisible('entry_mileage');
        $admin->fillForm(['equipment_id' => $other->id])->call('save')->assertHasNoFormErrors();
        $this->assertNull($order->fresh()->entry_mileage);
    }

    public function test_print_action_is_only_in_more_menu_and_pdf_omits_mileage_for_other_equipment(): void
    {
        $order = $this->order();
        $order->update(['equipment_id' => $this->equipment($order, EquipmentType::OTHER)->id, 'entry_mileage' => 90000]);
        $page = Livewire::test(ServiceOrderDetail::class, ['record' => $order->id]);
        $actions = $page->instance()->getFooter()->getData()['actions'];
        $print = collect($actions)->firstWhere('method', 'printOperationalSheet');
        $this->assertTrue($print['more']);
        $html = view('filament.operation.pages.partials.operational-sheet', ['order' => $page->get('order'), 'formData' => $page->get('formData'), 'errors' => new ViewErrorBag])->render();
        $this->assertStringNotContainsString('printOperationalSheet', $html);
        $pdfHtml = view('pdf.service-order-operational', ['record' => $order])->render();
        $this->assertStringNotContainsString('Quilometragem na entrada', $pdfHtml);
        $this->assertStringNotContainsString('90.000', $pdfHtml);
    }

    public function test_editing_description_preserves_legacy_diagnosis_and_status(): void
    {
        $order = $this->order();
        $defect = $order->defects()->create($this->defect() + ['diagnosis' => 'Diagnóstico anterior', 'solution' => 'Solução anterior', 'status' => 'resolved']);
        Livewire::test(ServiceOrderDetail::class, ['record' => $order->id])
            ->set('formData.defects.0.report', 'Relato atualizado')->call('save')->assertHasNoErrors();
        $this->assertSame('Diagnóstico anterior', $defect->fresh()->diagnosis);
        $this->assertSame('Solução anterior', $defect->fresh()->solution);
        $this->assertSame('resolved', $defect->fresh()->status);
    }
}
