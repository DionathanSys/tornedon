<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\FinancialCategory;
use Illuminate\Database\Seeder;

class FinancialCategorySeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            if (FinancialCategory::query()->where('company_id', $company->id)->exists()) {
                return;
            }

            $receitas = FinancialCategory::create([
                'company_id' => $company->id,
                'name' => 'Receitas',
                'allow_receivable' => false,
                'allow_cash_movement' => false,
                'is_active' => true,
            ]);

            FinancialCategory::create([
                'company_id' => $company->id,
                'parent_id' => $receitas->id,
                'name' => 'Servicos',
                'allow_receivable' => true,
                'allow_cash_movement' => true,
                'is_active' => true,
            ]);

            $despesas = FinancialCategory::create([
                'company_id' => $company->id,
                'name' => 'Despesas',
                'allow_payable' => false,
                'allow_cash_movement' => false,
                'is_active' => true,
            ]);

            FinancialCategory::create([
                'company_id' => $company->id,
                'parent_id' => $despesas->id,
                'name' => 'Fornecedores',
                'allow_payable' => true,
                'allow_cash_movement' => true,
                'is_active' => true,
            ]);
        });
    }
}
