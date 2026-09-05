<?php

namespace Tests\Feature\Filament\Management;

use App\Enum\User\ManagementRole;
use App\Filament\Management\Pages\CnpjProviderSettingsPage;
use App\Filament\Management\Resources\Companies\CompanyResource;
use App\Filament\Management\Resources\Companies\Pages\EditCompany;
use App\Filament\Management\Resources\Companies\RelationManagers\ProductSequenceRelationManager;
use App\Filament\Management\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\ProductSequence;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Management\UserAdministrationService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagementPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_access_management_panel(): void
    {
        $panel = Filament::getPanel('management');

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_admin_can_open_management_resources(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        $this->get(CompanyResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(CnpjProviderSettingsPage::getUrl())->assertOk();
    }

    public function test_admin_can_open_company_edit_and_see_all_sequence_managers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $company = Company::query()->create([
            'name' => 'Empresa de sequências',
            'address' => [],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        $this->get(CompanyResource::getUrl('edit', ['record' => $company]))
            ->assertOk();

        $editPage = Livewire::test(EditCompany::class, ['record' => (string) $company->getRouteKey()]);

        $this->assertCount(1, $editPage->instance()->getRelationManagers());

        $relationGroup = CompanyResource::getRelations()[0];

        $this->assertInstanceOf(RelationGroup::class, $relationGroup);
        $managers = $relationGroup
            ->ownerRecord($company)
            ->pageClass(EditCompany::class)
            ->getManagers();

        $this->assertCount(10, $managers);
    }

    public function test_sequence_relation_manager_can_create_and_edit_only_the_owner_company_sequence(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $company = Company::query()->create([
            'name' => 'Empresa proprietária',
            'address' => [],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $otherCompany = Company::query()->create([
            'name' => 'Outra empresa',
            'address' => [],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $otherSequence = ProductSequence::query()->create([
            'company_id' => $otherCompany->id,
            'last_number' => 987,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        Livewire::test(ProductSequenceRelationManager::class, [
            'ownerRecord' => $company,
            'pageClass' => EditCompany::class,
        ])
            ->assertTableActionVisible('create')
            ->assertDontSee((string) $otherSequence->last_number)
            ->callTableAction('create', data: ['last_number' => 123])
            ->assertHasNoErrors();

        $sequence = ProductSequence::query()
            ->where('company_id', $company->id)
            ->firstOrFail();

        $this->assertSame(123, $sequence->last_number);
        $this->assertSame($company->id, $sequence->company_id);

        Livewire::test(ProductSequenceRelationManager::class, [
            'ownerRecord' => $company,
            'pageClass' => EditCompany::class,
        ])
            ->callTableAction('edit', $sequence->getKey(), data: ['last_number' => 456])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_sequences', [
            'id' => $sequence->id,
            'company_id' => $company->id,
            'last_number' => 456,
        ]);
        $this->assertDatabaseHas('product_sequences', [
            'id' => $otherSequence->id,
            'company_id' => $otherCompany->id,
            'last_number' => 987,
        ]);
    }

    public function test_inactive_admin_cannot_access_management_or_admin_panels(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => false,
        ]);

        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('management')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_management_admin_cannot_access_sensitive_configuration_or_change_admin_roles(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false,
            'management_role' => ManagementRole::MANAGEMENT_ADMIN,
        ]);
        $superAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('management')));
        $this->assertFalse(CnpjProviderSettingsPage::canAccess());
        $this->get(CnpjProviderSettingsPage::getUrl())->assertForbidden();
        $this->assertFalse((new UserPolicy)->update($admin, $superAdmin));

        $this->expectException(AuthorizationException::class);
        app(UserAdministrationService::class)->assertCanCreate($admin, ManagementRole::MANAGEMENT_ADMIN->value);
    }

    public function test_management_admin_cannot_see_fiscal_sequence_managers(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false,
            'management_role' => ManagementRole::MANAGEMENT_ADMIN,
        ]);
        $company = Company::query()->create([
            'name' => 'Empresa operacional',
            'address' => [],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('management');

        $relationGroup = CompanyResource::getRelations()[0];
        $managers = $relationGroup
            ->ownerRecord($company)
            ->pageClass(EditCompany::class)
            ->getManagers();

        $this->assertCount(0, $managers);
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $actor = User::factory()->make(['is_admin' => true, 'is_active' => true]);

        $this->expectException(AuthorizationException::class);
        app(UserAdministrationService::class)->assertCanUpdate($actor, $superAdmin, [
            'management_role' => null,
        ]);
    }
}
