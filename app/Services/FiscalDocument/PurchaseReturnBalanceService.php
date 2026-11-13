<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PurchaseReturnBalanceService
{
    private const EPSILON = 0.0001;

    /**
     * @return Collection<int, float>
     */
    public function availableQuantities(FiscalDocument $originDocument, ?int $ignoreReturnDocumentId = null): Collection
    {
        $originDocument->loadMissing('items');

        $linkedQuantities = $this->activeLinksQuery($originDocument, $ignoreReturnDocumentId)
            ->selectRaw('origin_fiscal_document_item_id, SUM(linked_quantity) as linked_quantity')
            ->groupBy('origin_fiscal_document_item_id')
            ->pluck('linked_quantity', 'origin_fiscal_document_item_id');

        return $originDocument->items->mapWithKeys(function (FiscalDocumentItem $item) use ($linkedQuantities): array {
            $available = (float) $item->quantity - (float) ($linkedQuantities->get($item->id) ?? 0);

            return [$item->id => round(max($available, 0), 4)];
        });
    }

    public function availableQuantity(
        FiscalDocumentItem $originItem,
        ?int $ignoreReturnDocumentId = null,
    ): float {
        $originItem->loadMissing('fiscalDocument');

        $linkedQuantity = (float) $this->activeLinksQuery(
            $originItem->fiscalDocument,
            $ignoreReturnDocumentId,
        )
            ->where('origin_fiscal_document_item_id', $originItem->id)
            ->sum('linked_quantity');

        return round(max((float) $originItem->quantity - $linkedQuantity, 0), 4);
    }

    public function hasAvailableBalance(FiscalDocument $originDocument): bool
    {
        return $this->availableQuantities($originDocument)
            ->contains(fn (float $quantity): bool => $quantity > self::EPSILON);
    }

    public function withAvailableBalance(Builder $query): Builder
    {
        return $query->withExists(['items as has_available_return_balance' => function (Builder $items): void {
            $linkedQuantity = $this->activeLinksQuery()
                ->whereColumn('origin_fiscal_document_item_id', 'fiscal_document_items.id')
                ->selectRaw('COALESCE(SUM(linked_quantity), 0)')->toBase();

            $items->whereRaw('quantity - ('.$linkedQuantity->toSql().') > CAST(? AS DECIMAL(20,4))', [
                ...$linkedQuantity->getBindings(), self::EPSILON,
            ]);
        }]);
    }

    public function isWithinAvailableBalance(
        FiscalDocumentItem $originItem,
        float $requestedQuantity,
        ?int $ignoreReturnDocumentId = null,
    ): bool {
        return $requestedQuantity > self::EPSILON
            && $requestedQuantity <= $this->availableQuantity($originItem, $ignoreReturnDocumentId) + self::EPSILON;
    }

    private function activeLinksQuery(?FiscalDocument $originDocument = null, ?int $ignoreReturnDocumentId = null): Builder
    {
        return FiscalDocumentItemOrigin::query()
            ->when($originDocument, fn (Builder $query) => $query->where('origin_fiscal_document_id', $originDocument->id))
            ->when($ignoreReturnDocumentId, function ($query) use ($ignoreReturnDocumentId): void {
                $query->where('return_fiscal_document_id', '!=', $ignoreReturnDocumentId);
            })
            ->whereHas('returnDocument', function ($query): void {
                $query
                    ->where('issue_purpose', IssuePurpose::DEVOLUCAO->value)
                    ->where('operation_nature', OperationNature::DEVOLUCAO_COMPRA->value)
                    ->where('status', '!=', Status::CANCELLED->value)
                    ->where(function ($query): void {
                        $query->whereNull('canceled')->orWhere('canceled', false);
                    })
                    ->where(function ($query): void {
                        $query->whereNull('nfe_status')
                            ->orWhereNotIn('nfe_status', [
                                NfeStatus::REJECTED->value,
                                NfeStatus::CANCELED->value,
                            ]);
                    });
            });
    }
}
