<?php

namespace App\Services\Financial;

use App\Models\FinancialCategory;
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

    public function assertCategoryIsUsable(int $categoryId, int $companyId, string $scope): FinancialCategory
    {
        $category = FinancialCategory::query()
            ->where('company_id', $companyId)
            ->find($categoryId);

        if (! $category) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['Categoria financeira nao encontrada para a empresa informada.'],
            ]);
        }

        if (! $category->is_active) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['A categoria financeira selecionada esta inativa.'],
            ]);
        }

        if (! $category->isLeaf()) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['Apenas subcategorias finais podem ser usadas em lancamentos financeiros.'],
            ]);
        }

        if (! $category->allows($scope)) {
            throw ValidationException::withMessages([
                'financial_category_id' => ['A categoria financeira selecionada nao pode ser usada neste tipo de lancamento.'],
            ]);
        }

        return $category;
    }
}
