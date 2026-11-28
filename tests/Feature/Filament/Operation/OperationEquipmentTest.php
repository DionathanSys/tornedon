<?php

namespace Tests\Feature\Filament\Operation;

use App\Enum\Equipment\Type;
use App\Filament\Operation\OperationModules;
use App\Filament\Operation\Resources\Equipments\EquipmentResource;
use App\Filament\Operation\Resources\Equipments\Pages\CreateEquipment;
use App\Filament\Operation\Resources\Equipments\Pages\EditEquipment;
use App\Livewire\OperationMenu;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OperationEquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_lists_and_edit_pages_are_scoped_to_the_current_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $otherCompany = Company::factory()->create(['created_by' => $user->id]);
        $owner = $this->createOwner($company, $user, 'Proprietário Operação');
        $otherOwner = $this->createOwner($otherCompany, $user, 'Proprietário Outro');

        $own = Equipment::query()->create([
            'company_id' => $company->id, 'owner_id' => $owner->id, 'name' => 'Equipamento da empresa',
            'type' => Type::TRUCK, 'placa' => 'ABC1234', 'serial_number' => 'OP-001', 'created_by' => $user->id,
        ]);
        $foreign = Equipment::query()->create([
            'company_id' => $otherCompany->id, 'owner_id' => $otherOwner->id, 'name' => 'Equipamento oculto',
            'type' => Type::TRUCK, 'placa' => 'XYZ9876', 'serial_number' => 'OP-002', 'created_by' => $user->id,
        ]);

        $this->get(EquipmentResource::getUrl('index', tenant: $company))
            ->assertOk()
            ->assertSee('operation-ui', false)
            ->assertSee('Equipamento da empresa')
            ->assertDontSee('Equipamento oculto');

        $this->get(EquipmentResource::getUrl('edit', ['record' => $own], tenant: $company))->assertOk();
        $this->get(EquipmentResource::getUrl('edit', ['record' => $foreign], tenant: $company))->assertNotFound();
    }

    public function test_equipment_created_in_operation_belongs_to_the_current_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $owner = $this->createOwner($company, $user, 'Proprietário Cadastro');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'owner_id' => $owner->id,
                'name' => 'Caminhão cadastrado na operação',
                'type' => Type::TRUCK->value,
                'placa' => 'OPX1234',
                'mark' => 'Mercedes',
                'model' => 'Atego',
                'serial_number' => 'OP-CREATE-001',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $equipment = Equipment::query()->where('name', 'Caminhão cadastrado na operação')->sole();
        $this->assertSame($company->id, $equipment->company_id);
        $this->assertSame($user->id, $equipment->created_by);
        $this->assertSame('OPX1234', $equipment->placa);
    }

    public function test_equipment_module_can_be_hidden_and_blocked_by_permission(): void
    {
        [$user, $company] = $this->authenticateTenant();

        $user->companyAccesses()->updateOrCreate(['company_id' => $company->id], [
            'permissions' => ['requisitions.access'],
            'panels' => ['operation' => true],
            'navigation' => ['operation' => ['home', 'requisitions']],
        ]);

        $this->assertSame(['home', 'requisitions'], array_column(OperationModules::navigation(), 'key'));
        $this->get(EquipmentResource::getUrl('index', tenant: $company))->assertForbidden();
        $this->get(EquipmentResource::getUrl('create', tenant: $company))->assertForbidden();
        Livewire::test(CreateEquipment::class)->assertForbidden();
        Livewire::test(OperationMenu::class)->assertDontSee('Equipamentos');
    }

    public function test_equipment_creation_rejects_an_owner_from_another_company(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $otherCompany = Company::factory()->create(['created_by' => $user->id]);
        $owner = $this->createOwner($otherCompany, $user, 'Proprietário externo');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'owner_id' => $owner->id,
                'name' => 'Equipamento inválido',
                'type' => Type::OTHER->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['owner_id']);

        $this->assertDatabaseMissing('equipments', ['company_id' => $company->id, 'name' => 'Equipamento inválido']);
    }

    public function test_equipment_can_be_edited_without_changing_its_company_or_author(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $owner = $this->createOwner($company, $user, 'Proprietário Edição');
        $equipment = Equipment::query()->create([
            'company_id' => $company->id, 'owner_id' => $owner->id,
            'name' => 'Equipamento original', 'type' => Type::OTHER, 'created_by' => $user->id,
        ]);

        Livewire::test(EditEquipment::class, ['record' => $equipment->id])
            ->fillForm(['name' => 'Equipamento atualizado', 'serial_number' => 'EDIT-001'])
            ->call('save')
            ->assertHasNoFormErrors();

        $equipment->refresh();
        $this->assertSame('Equipamento atualizado', $equipment->name);
        $this->assertSame('EDIT-001', $equipment->serial_number);
        $this->assertSame($company->id, $equipment->company_id);
        $this->assertSame($user->id, $equipment->created_by);
        $this->assertSame($owner->id, $equipment->owner_id);
    }

    public function test_revoking_equipment_access_blocks_an_already_mounted_create_page(): void
    {
        [$user, $company] = $this->authenticateTenant();
        $owner = $this->createOwner($company, $user, 'Proprietário Revogação');
        $page = Livewire::test(CreateEquipment::class)->fillForm([
            'owner_id' => $owner->id, 'name' => 'Cadastro revogado', 'type' => Type::OTHER->value,
        ]);

        $user->companyAccesses()->create([
            'company_id' => $company->id, 'permissions' => [], 'panels' => ['operation' => true], 'navigation' => [],
        ]);

        $page->call('create')->assertForbidden();
        $this->assertDatabaseMissing('equipments', ['name' => 'Cadastro revogado']);
    }

    /**
     * @return array{User, Company}
     */
    private function authenticateTenant(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('operation');
        Filament::setTenant($company);

        return [$user, $company];
    }

    private function createOwner(Company $company, User $user, string $name): Partner
    {
        $owner = Partner::query()->create([
            'name' => $name.' '.Str::uuid(),
            'document_type' => 'CPF',
            'document_number' => fake()->numerify('###########'),
            'created_by' => $user->id,
        ]);
        CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $owner->id,
            'type' => ['customer'],
            'is_active' => true,
        ]);

        return $owner;
    }
}
