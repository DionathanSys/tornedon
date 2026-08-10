<?php

namespace App\Services\Financial;

use App\Models\ChartAccount;
use App\Models\CostCenter;
use App\Models\FinancialCategory;
use App\Models\ResultCenter;
use Illuminate\Validation\ValidationException;

class FinancialClassificationService
{
    public function resolveInstallmentCategoryId(?int $categoryId, int $companyId, string $scope): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return $this->assertCategoryIsUsable($categoryId, $companyId, $scope)->id;
    }

    public function resolveChartAccountIdFromCategoryId(?int $categoryId, int $companyId, string $scope): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return $this->assertCategoryIsUsable($categoryId, $companyId, $scope)->chart_account_id;
    }

    public function assertChartAccountBelongsToCompany(?int $chartAccountId, int $companyId): ?int
    {
        if ($chartAccountId === null) {
            return null;
        }

        $account = ChartAccount::query()
            ->where('company_id', $companyId)
            ->find($chartAccountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'chart_account_id' => ['Conta do plano nao encontrada para a empresa informada.'],
            ]);
        }

        return $account->id;
    }

    public function assertCostCenterBelongsToCompany(?int $costCenterId, int $companyId): ?int
    {
        if ($costCenterId === null) {
            return null;
        }

        $center = CostCenter::query()
            ->where('company_id', $companyId)
            ->find($costCenterId);

        if (! $center) {
            throw ValidationException::withMessages([
                'cost_center_id' => ['Centro de custo nao encontrado para a empresa informada.'],
            ]);
        }

        return $center->id;
    }

    public function assertResultCenterBelongsToCompany(?int $resultCenterId, int $companyId): ?int
    {
        if ($resultCenterId === null) {
            return null;
        }

        $center = ResultCenter::query()
            ->where('company_id', $companyId)
            ->find($resultCenterId);

        if (! $center) {
            throw ValidationException::withMessages([
                'result_center_id' => ['Centro de resultado nao encontrado para a empresa informada.'],
            ]);
        }

        return $center->id;
    }

    public function assertCategoryIsUsable(int $categoryId, int $companyId, string $scope): FinancialCategory
    {
        $category = FinancialCategory::query()
            ->where('company_id', $companyId)
            ->find($categoryId);

        if (! $category) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['Categoria financeira não encontrada para a empresa informada.'],
            ]);
        }

        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['A categoria financeira selecionada esta inativa.'],
            ]);
        }

        if (! $category->isLeaf()) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['Apenas subcategorias finais podem ser usadas em lançamentos financeiros.'],
            ]);
        }

        if (! $category->allows($scope)) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['A categoria financeira selecionada não pode ser usada neste tipo de lançamento.'],
            ]);
        }

        return $category;
    }
}
