<?php

namespace Tests\Feature\Models;

use App\Enum\Financial\ChartAccountType;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialCategoryChartAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_can_exist_without_chart_account(): void
    {
        $company = $this->createCompany('Empresa Sem Plano');

        $category = FinancialCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Diesel',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
        ]);

        $this->assertNull($category->chart_account_id);
    }

    public function test_category_can_reference_chart_account_from_same_company(): void
    {
        $company = $this->createCompany('Empresa Com Plano');
        $account = $this->createAccount($company->id, '2.01.01', 'Combustíveis');

        $category = FinancialCategory::query()->create([
            'company_id' => $company->id,
            'chart_account_id' => $account->id,
            'name' => 'Diesel',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
        ]);

        $this->assertSame($account->id, $category->chartAccount->id);
    }

    public function test_category_cannot_reference_chart_account_from_another_company(): void
    {
        $company = $this->createCompany('Empresa A');
        $otherCompany = $this->createCompany('Empresa B');
        $foreignAccount = $this->createAccount($otherCompany->id, '2.01.01', 'Combustíveis');

        $this->expectException(ValidationException::class);

        FinancialCategory::query()->create([
            'company_id' => $company->id,
            'chart_account_id' => $foreignAccount->id,
            'name' => 'Diesel',
            'allow_payable' => true,
            'allow_cash_movement' => true,
            'is_active' => true,
        ]);
    }

    public function test_updating_category_to_foreign_chart_account_is_blocked(): void
    {
        $company = $this->createCompany('Empresa Principal');
        $otherCompany = $this->createCompany('Empresa Externa');
        $category = FinancialCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Pneus',
            'allow_payable' => true,
            'is_active' => true,
        ]);
        $foreignAccount = $this->createAccount($otherCompany->id, '2.01.02', 'Pneus');

        $this->expectException(ValidationException::class);

        $category->update(['chart_account_id' => $foreignAccount->id]);
    }

    private function createCompany(string $name): Company
    {
        $user = User::factory()->create();

        return Company::query()->create([
            'name' => $name,
            'document_number' => fake()->numerify('##############'),
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
    }

    private function createAccount(int $companyId, string $code, string $name): ChartAccount
    {
        return ChartAccount::query()->create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'type' => ChartAccountType::COST->value,
            'is_postable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
