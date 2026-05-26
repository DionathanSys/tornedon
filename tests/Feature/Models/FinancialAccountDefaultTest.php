<?php

namespace Tests\Feature\Models;

use App\Enum\Financial\FinancialAccountType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_a_default_account_unsets_previous_default_for_same_company(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Financeira',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $first = $this->createFinancialAccount($company->id, $user->id, 'Conta 1', true);
        $second = $this->createFinancialAccount($company->id, $user->id, 'Conta 2', false);

        $second->update(['is_default' => true]);

        $this->assertFalse((bool) $first->fresh()->is_default);
        $this->assertTrue((bool) $second->fresh()->is_default);
        $this->assertSame($second->id, FinancialAccount::defaultIdForCompany($company->id));
    }

    public function test_default_id_for_company_skips_inactive_default_and_falls_back_to_active_account(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Default',
            'document_number' => '22345678000188',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);

        $inactiveDefault = $this->createFinancialAccount($company->id, $user->id, 'Conta Inativa', true, false);
        $activeFallback = $this->createFinancialAccount($company->id, $user->id, 'Conta Ativa', false, true);

        $this->assertSame($activeFallback->id, FinancialAccount::defaultIdForCompany($company->id));
        $this->assertNotSame($inactiveDefault->id, FinancialAccount::defaultIdForCompany($company->id));
    }

    private function createFinancialAccount(int $companyId, int $userId, string $name, bool $isDefault, bool $isActive = true): FinancialAccount
    {
        return FinancialAccount::query()->create([
            'company_id' => $companyId,
            'name' => $name,
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => $isActive,
            'is_default' => $isDefault,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
