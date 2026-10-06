<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\StockMovement\Type as MovementType;
use App\Models\FiscalDocument;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\Product\ProductUnitConversionService;
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
                ->where('type', MovementType::RETURN->value)
                ->first();

            if (! $originalMovement) {
                continue;
            }

            $alreadyReversed = StockMovement::query()
                ->where('source_type', 'fiscal_document_item_return_reversal')
                ->where('source_id', $item->id)
                ->where('type', MovementType::EXIT->value)
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

            $movementData = $this->resolveMovementData($item->product, $item, $stock->id, $document->company_id);

            if ($movementData === null) {
                $result['errors'][] = "Produto {$item->product?->product_code} com unidade inválida para estorno.";

                continue;
            }

            $movement = $this->stockMovementService->create(array_merge($movementData, [
                'type' => MovementType::EXIT->value,
                'unit_price' => (float) $item->unit_price,
                'reason' => "Estorno da devolução de compra NF #{$document->document_number} - Produto: {$item->product?->product_code}",
                'source_type' => 'fiscal_document_item_return_reversal',
                'source_id' => $item->id,
                'additional_info' => [
                    'reversal_of_stock_movement_id' => $originalMovement->id,
                    'return_fiscal_document_id' => $document->id,
                ],
            ]), $userId);

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

    private function resolveMovementData(mixed $product, mixed $item, int $stockId, int $companyId): ?array
    {
        $conversionService = app(ProductUnitConversionService::class);
        $commercialUnit = (string) ($item->unit_of_measure ?? '');
        $commercialQuantity = (float) ($item->quantity ?? 0);
        $taxableQuantity = (float) ($item->taxable_quantity ?? 0);

        if (
            $commercialUnit !== ''
            && $commercialQuantity > 0
            && $taxableQuantity > 0
            && $conversionService->isAllowedUnit($product, $commercialUnit)
        ) {
            return [
                'product_stock_id' => $stockId,
                'product_id' => $item->product_id,
                'company_id' => $companyId,
                'operational_unit' => $commercialUnit,
                'operational_quantity' => $commercialQuantity,
                'base_unit' => $product->unit?->value,
                'base_quantity' => $taxableQuantity,
                'conversion_factor_snapshot' => round($taxableQuantity / $commercialQuantity, 8),
                'quantity' => $taxableQuantity,
            ];
        }

        $operationalUnit = (string) ($item->taxable_unit ?: ($item->unit_of_measure ?? $product->unit?->value));

        if (! $conversionService->isAllowedUnit($product, $operationalUnit)) {
            return null;
        }

        return [
            'product_stock_id' => $stockId,
            'product_id' => $item->product_id,
            'company_id' => $companyId,
            'operational_unit' => $operationalUnit,
            'quantity' => (float) ($item->taxable_quantity ?? $item->quantity),
        ];
    }
}
