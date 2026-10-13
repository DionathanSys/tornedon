<?php

namespace Tests\Feature\Filament\Mobile;

use App\Enum\Equipment\Type as EquipmentType;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\ListMobileServiceOrders;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MobileServiceOrdersTableTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['created_by' => $this->user->id]);
        $this->user->companies()->attach($this->company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
        Filament::setCurrentPanel('mobile');
        Filament::setTenant($this->company);
    }

    public function test_general_search_matches_equipment_identifier_fields(): void
    {
        $customer = Partner::factory()->create(['name' => 'Cliente Mobile']);

        $vehicle = Equipment::query()->create([
            'name' => 'Trator agrícola',
            'type' => EquipmentType::CAR,
            'placa' => 'AGRO123',
            'serial_number' => 'SERIAL-IGNORADO',
            'company_id' => $this->company->id,
            'owner_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        $machine = Equipment::query()->create([
            'name' => 'Gerador industrial',
            'type' => EquipmentType::OTHER,
            'serial_number' => 'SER-AGRO-987',
            'company_id' => $this->company->id,
            'owner_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        ServiceOrder::factory()->create([
            'number' => 'OS-PLACA',
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'equipment_id' => $vehicle->id,
            'created_by' => $this->user->id,
        ]);

        ServiceOrder::factory()->create([
            'number' => 'OS-SERIE',
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'equipment_id' => $machine->id,
            'created_by' => $this->user->id,
        ]);

        $page = Livewire::test(ListMobileServiceOrders::class)
            ->set('tableSearch', 'AGRO123');

        $page
            ->assertSee('OS-PLACA')
            ->assertDontSee('OS-SERIE')
            ->set('tableSearch', 'SER-AGRO-987')
            ->assertSee('OS-SERIE')
            ->assertDontSee('OS-PLACA')
            ->set('tableSearch', 'Gerador industrial')
            ->assertSee('OS-SERIE')
            ->assertDontSee('OS-PLACA');
    }
}
