<?php

namespace Tests\Feature\Models;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable;
use App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages\ListCardInstitutions;
use App\Models\CardInstitution;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CardInstitutionDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_toggle_changes_default_without_affecting_other_tenant(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $company = Company::create(['name' => 'Empresa A', 'document_number' => '12345678000191', 'address' => ['state' => 'SP'], 'created_by' => $user->id]);
        $other = Company::create(['name' => 'Empresa B', 'document_number' => '12345678000192', 'address' => ['state' => 'SP'], 'created_by' => $user->id]);
        $user->companies()->attach($company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);
        $first = CardInstitution::create(['company_id' => $company->id, 'name' => 'Stone', 'is_default' => true, 'settlement_days' => 30]);
        $second = CardInstitution::create(['company_id' => $company->id, 'name' => 'Rede', 'settlement_days' => 15]);
        $foreign = CardInstitution::create(['company_id' => $other->id, 'name' => 'Cielo', 'is_default' => true, 'settlement_days' => 10]);

        Livewire::test(ListCardInstitutions::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$foreign])
            ->call('updateTableColumnState', 'is_default', (string) $second->id, true)
            ->assertHasNoErrors();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($foreign->fresh()->is_default);
        Livewire::test(CreateAccountReceivable::class)
            ->assertSet('data.card_payment_profile_id', $second->id)
            ->assertSet('data.auto_register_receipt_on_due_date', false);
    }

    public function test_default_is_replaced_only_within_its_company_and_cleared_when_inactive(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Empresa A', 'document_number' => '12345678000191', 'address' => ['state' => 'SP'], 'created_by' => $user->id]);
        $other = Company::create(['name' => 'Empresa B', 'document_number' => '12345678000192', 'address' => ['state' => 'SP'], 'created_by' => $user->id]);
        $first = CardInstitution::create(['company_id' => $company->id, 'name' => 'Stone', 'is_default' => true, 'settlement_days' => 30]);
        $foreign = CardInstitution::create(['company_id' => $other->id, 'name' => 'Cielo', 'is_default' => true, 'settlement_days' => 10]);
        $second = CardInstitution::create(['company_id' => $company->id, 'name' => 'Rede', 'settlement_days' => 15]);

        $second->update(['is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($foreign->fresh()->is_default);
        $this->assertSame($second->id, CardInstitution::defaultIdForCompany($company->id));
        $this->assertSame($foreign->id, CardInstitution::defaultIdForCompany($other->id));

        $second->update(['active' => false]);
        $this->assertFalse($second->fresh()->is_default);
        $this->assertNull(CardInstitution::defaultIdForCompany($company->id));
        $this->assertArrayNotHasKey($second->id, CardInstitution::optionsForCompany($company->id));
    }
}
