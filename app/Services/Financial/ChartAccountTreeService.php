<?php

namespace App\Services\Financial;

use App\Models\ChartAccount;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChartAccountTreeService
{
    /**
     * @return Collection<int, ChartAccount>
     */
    public function ancestors(ChartAccount $account): Collection
    {
        return $account->ancestors();
    }

    public function root(ChartAccount $account): ChartAccount
    {
        return $account->root();
    }

    /**
     * @return Collection<int, ChartAccount>
     */
    public function descendants(ChartAccount $account, bool $includeSelf = false): Collection
    {
        return $account->descendants($includeSelf);
    }

    /**
     * @throws ValidationException
     */
    public function assertParentIsValid(ChartAccount $account, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($account->exists && (int) $parentId === (int) $account->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['Uma conta nao pode ser pai dela mesma.'],
            ]);
        }

        $parent = ChartAccount::query()->find($parentId);

        if (! $parent || (int) $parent->company_id !== (int) $account->company_id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A conta pai deve pertencer a mesma empresa.'],
            ]);
        }

        $current = $parent;
        $visited = [];

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A hierarquia do plano de contas contem um ciclo.'],
                ]);
            }

            if ($account->exists && $currentId === (int) $account->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A conta pai nao pode ser descendente da conta atual.'],
                ]);
            }

            $visited[$currentId] = true;
            $current = $current->parent()->first();
        }
    }
}
