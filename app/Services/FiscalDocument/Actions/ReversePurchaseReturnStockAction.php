<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\StockMovement\Type as MovementType;
use App\Models\FiscalDocument;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class ReversePurchaseReturnStockAction
{
    public function __construct(
        private readonly StockMovementService $stockMovementService = new StockMovementService,
    ) {}

    /**
     * @return array{reversed_movements:int,errors:string[]}
     */
    public function execute(FiscalDocument $document, int $userId): array
    {
        if ($document->isOperationalHistory()) {
            return ['reversed_movements' => 0, 'errors' => []];
        }

        $document->loadMissing(['items.product', 'items.product.stock']);

        $result = [
            'reversed_movements' => 0,
            'errors' => [],
        ];

        if (! $document->isPurchaseReturn()) {
            return $result;
        }

        foreach ($document->items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            $originalMovement = StockMovement::query()
                ->where('source_type', 'fiscal_document_item')
                ->where('source_id', $item->id)
                ->where('company_id', $document->company_id)
                ->whereIn('type', [MovementType::EXIT->value, MovementType::RETURN->value])
                ->first();

            if (! $originalMovement) {
                continue;
            }

            $alreadyReversed = StockMovement::query()
                ->where('source_type', 'fiscal_document_item_return_reversal')
                ->where('source_id', $item->id)
                ->where('company_id', $document->company_id)
                ->whereIn('type', [MovementType::ENTRY->value, MovementType::EXIT->value])
                ->exists();

            if ($alreadyReversed) {
                continue;
            }

            $stock = ProductStock::query()
                ->where('product_id', $item->product_id)
                ->where('company_id', $document->company_id)
                ->first();

            if (! $stock) {
                $result['errors'][] = "Produto #{$item->product?->product_code} sem estoque cadastrado para estorno.";

                continue;
            }

            // Reverte o efeito efetivamente lançado, inclusive devoluções legadas
            // que eram registradas como entrada (RETURN), usando o snapshot original.
            $movement = $this->stockMovementService->create([
                'product_stock_id' => $stock->id,
                'product_id' => $item->product_id,
                'company_id' => $document->company_id,
                'operational_unit' => $originalMovement->operational_unit ?: $item->product->unit?->value,
                'operational_quantity' => $originalMovement->resolvedOperationalQuantity(),
                'base_unit' => $originalMovement->base_unit,
                'base_quantity' => $originalMovement->resolvedBaseQuantity(),
                'conversion_factor_snapshot' => $originalMovement->conversion_factor_snapshot ?: 1,
                'quantity' => $originalMovement->resolvedBaseQuantity(),
                'type' => $originalMovement->type === MovementType::EXIT
                    ? MovementType::ENTRY->value
                    : MovementType::EXIT->value,
                // Repõe a quantidade sem tratar o estorno como uma nova compra.
                'unit_price' => 0,
                'reason' => "Estorno da devolução de compra NF #{$document->document_number} - Produto: {$item->product?->product_code}",
                'source_type' => 'fiscal_document_item_return_reversal',
                'source_id' => $item->id,
                'additional_info' => [
                    'reversal_of_stock_movement_id' => $originalMovement->id,
                    'return_fiscal_document_id' => $document->id,
                ],
            ], $userId);

            if ($this->stockMovementService->hasError() || ! $movement) {
                $result['errors'][] = "Erro ao estornar devolução do produto {$item->product?->product_code}: "
                    .$this->stockMovementService->getMessage();

                continue;
            }

            $result['reversed_movements']++;
        }

        if ($result['errors'] === []) {
            $document->forceFill([
                'return_stock_reversed_at' => now(),
                'return_stock_reversed_by' => $userId,
            ])->save();
        }

        Log::info('ReversePurchaseReturnStockAction: estorno concluído', [
            'fiscal_document_id' => $document->id,
            'reversed_movements' => $result['reversed_movements'],
            'errors' => $result['errors'],
        ]);

        return $result;
    }
}
