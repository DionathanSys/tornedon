<?php

namespace Tests\Feature\Filament\Equipments;

use App\Enum\Equipment\Type;
use App\Filament\Clusters\Partners\Resources\Equipments\Pages\EditEquipment;
use App\Filament\Clusters\Partners\Resources\Equipments\Pages\ListEquipments;
use App\Filament\Mobile\Resources\Equipments\Pages\EditEquipment as MobileEditEquipment;
use App\Filament\Mobile\Resources\Equipments\Pages\ListEquipment as MobileListEquipment;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DuplicateEquipmentActionTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tornedon-views-test-'.Str::uuid();
        File::ensureDirectoryExists($compiledPath);
        config(['view.compiled' => $compiledPath]);
        $cachePath = (new \ReflectionClass(app('blade.compiler')))->getProperty('cachePath');
        $cachePath->setValue(app('blade.compiler'), $compiledPath);

        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa de Equipamentos',
            'address' => ['city' => 'São Paulo', 'state' => 'SP'],
            'email' => 'equipamentos@example.com',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $owner = Partner::factory()->create();
        CompanyPartner::query()->create([
            'company_id' => $company->id,
            'partner_id' => $owner->id,
            'type' => ['customer'],
            'is_active' => true,
        ]);

        $this->equipment = Equipment::query()->create([
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'name' => 'Caminhão 01',
            'type' => Type::TRUCK,
            'placa' => 'ABC1234',
            'mark' => 'Marca original',
            'model' => 'Modelo original',
            'serial_number' => 'SERIE-001',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
    }

    public static function pages(): array
    {
        return [
            'edição admin' => ['admin', EditEquipment::class, false],
            'listagem admin' => ['admin', ListEquipments::class, true],
            'edição mobile' => ['mobile', MobileEditEquipment::class, false],
            'listagem mobile' => ['mobile', MobileListEquipment::class, true],
        ];
    }

    #[DataProvider('pages')]
    public function test_it_prefills_the_modal_and_creates_an_independent_copy(string $panel, string $page, bool $table): void
    {
        Filament::setCurrentPanel($panel);
        $original = $this->equipment->refresh()->getAttributes();
        $action = TestAction::make('duplicateEquipment');
        if ($table) {
            $action->table($this->equipment);
        }

        Livewire::test($page, $table ? [] : ['record' => (string) $this->equipment->getRouteKey()])
            ->mountAction($action)
            ->assertSchemaStateSet([
                'owner_id' => $this->equipment->owner_id,
                'name' => 'Caminhão 01',
                'type' => Type::TRUCK->value,
                'placa' => 'ABC1234',
                'mark' => 'Marca original',
                'model' => 'Modelo original',
                'serial_number' => null,
            ])
            ->fillForm(['mark' => 'Marca da cópia'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $copy = Equipment::query()->whereKeyNot($this->equipment->id)->sole();
        $this->assertSame('Caminhão 01', $copy->name);
        $this->assertSame('ABC1234', $copy->placa);
        $this->assertSame('Marca da cópia', $copy->mark);
        $this->assertSame('Modelo original', $copy->model);
        $this->assertSame($this->equipment->owner_id, $copy->owner_id);
        $this->assertSame($this->equipment->company_id, $copy->company_id);
        $this->assertSame(auth()->id(), $copy->created_by);
        $this->assertNull($copy->serial_number);
        $this->assertSame($original, $this->equipment->fresh()->getAttributes());
    }

    public function test_it_validates_the_composite_unique_constraint(): void
    {
        Livewire::test(EditEquipment::class, ['record' => (string) $this->equipment->id])
            ->callAction('duplicateEquipment', data: ['serial_number' => 'SERIE-001'])
            ->assertHasActionErrors(['serial_number' => 'unique']);

        $this->assertDatabaseCount('equipments', 1);
    }

    public function test_it_rejects_an_owner_from_another_company(): void
    {
        $otherOwner = Partner::factory()->create();

        Livewire::test(EditEquipment::class, ['record' => (string) $this->equipment->id])
            ->callAction('duplicateEquipment', data: ['owner_id' => $otherOwner->id])
            ->assertHasActionErrors(['owner_id']);

        $this->assertDatabaseCount('equipments', 1);
    }

    public function test_it_allows_the_same_serial_number_when_the_description_is_different(): void
    {
        Livewire::test(EditEquipment::class, ['record' => (string) $this->equipment->id])
            ->callAction('duplicateEquipment', data: [
                'name' => 'Caminhão 02',
                'serial_number' => 'SERIE-001',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('equipments', [
            'name' => 'Caminhão 02',
            'serial_number' => 'SERIE-001',
            'company_id' => $this->equipment->company_id,
            'owner_id' => $this->equipment->owner_id,
        ]);
    }
}
