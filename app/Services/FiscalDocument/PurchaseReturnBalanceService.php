<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
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

    public function isWithinAvailableBalance(
        FiscalDocumentItem $originItem,
        float $requestedQuantity,
        ?int $ignoreReturnDocumentId = null,
    ): bool {
        return $requestedQuantity > self::EPSILON
            && $requestedQuantity <= $this->availableQuantity($originItem, $ignoreReturnDocumentId) + self::EPSILON;
    }

    private function activeLinksQuery(FiscalDocument $originDocument, ?int $ignoreReturnDocumentId = null)
    {
        return FiscalDocumentItemOrigin::query()
            ->where('origin_fiscal_document_id', $originDocument->id)
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
