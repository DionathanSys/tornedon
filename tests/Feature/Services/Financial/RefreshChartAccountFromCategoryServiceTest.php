<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\Financial\AccountingNature;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\ChartAccountType;
use App\Enum\Financial\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Financial\RefreshChartAccountFromCategoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshChartAccountFromCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_a_cash_movement_chart_account_from_its_category(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Empresa Classificacao',
            'document_number' => '12345678000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $financialAccount = FinancialAccount::create([
            'company_id' => $company->id,
            'name' => 'Banco Principal',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 0,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $chartAccount = ChartAccount::create([
            'company_id' => $company->id,
            'code' => '3.01',
            'name' => 'Receita de Servicos',
            'type' => ChartAccountType::REVENUE->value,
            'nature' => AccountingNature::CREDIT->value,
            'is_postable' => true,
            'is_active' => true,
        ]);
        $category = FinancialCategory::create([
            'company_id' => $company->id,
            'name' => 'Servicos',
            'chart_account_id' => $chartAccount->id,
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $movement = CashMovement::create([
            'company_id' => $company->id,
            'financial_account_id' => $financialAccount->id,
            'financial_category_id' => $category->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-08-20',
            'amount' => 100,
            'description' => 'Movimento legado',
            'origin_type' => 'manual',
            'created_by' => $user->id,
        ]);

        $result = app(RefreshChartAccountFromCategoryService::class)->refresh(new Collection([$movement]), $user->id);

        $this->assertSame(['updated' => 1, 'skipped' => 0], $result);
        $this->assertDatabaseHas('cash_movements', [
            'id' => $movement->id,
            'chart_account_id' => $chartAccount->id,
            'updated_by' => $user->id,
        ]);
    }
}
