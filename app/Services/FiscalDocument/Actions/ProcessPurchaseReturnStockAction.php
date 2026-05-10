<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\StockMovement\Type as MovementType;
use App\Models\FiscalDocument;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\Product\ProductUnitConversionService;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class ProcessPurchaseReturnStockAction
{
    public function __construct(
        private readonly StockMovementService $stockMovementService = new StockMovementService(),
    ) {}

    /**
     * @return array{stock_movements:int,errors:string[]}
     */
    public function execute(FiscalDocument $document, int $userId): array
    {
        $document->loadMissing(['items.product', 'items.product.stock']);

        $result = [
            'stock_movements' => 0,
            'errors' => [],
        ];

        if (! $document->isPurchaseReturn()) {
            return $result;
        }

        foreach ($document->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $item->product;

            if (! $product || ! $product->has_stock_control) {
                continue;
            }

            if (StockMovement::query()
                ->where('source_type', 'fiscal_document_item')
                ->where('source_id', $item->id)
                ->where('type', MovementType::RETURN->value)
                ->exists()) {
                continue;
            }

            $stock = ProductStock::query()
                ->where('product_id', $item->product_id)
                ->where('company_id', $document->company_id)
                ->first();

            if (! $stock) {
                $result['errors'][] = "Produto #{$product->product_code} sem estoque cadastrado. Movimentação ignorada.";
                continue;
            }

            $operationalUnit = (string) ($item->taxable_unit ?: ($item->unit_of_measure ?? $product->unit?->value));

            if (! app(ProductUnitConversionService::class)->isAllowedUnit($product, $operationalUnit)) {
                $result['errors'][] = "Produto {$product->product_code} com unidade {$operationalUnit} não cadastrada. Movimentação ignorada.";
                continue;
            }

            $movement = $this->stockMovementService->create([
                'product_stock_id' => $stock->id,
                'product_id' => $item->product_id,
                'company_id' => $document->company_id,
                'type' => MovementType::RETURN->value,
                'operational_unit' => $operationalUnit,
                'quantity' => (float) ($item->taxable_quantity ?? $item->quantity),
                'unit_price' => (float) $item->unit_price,
                'reason' => "Devolução de compra NF #{$document->document_number} - Produto: {$product->product_code}",
                'source_type' => 'fiscal_document_item',
                'source_id' => $item->id,
            ], $userId);

            if ($this->stockMovementService->hasError() || ! $movement) {
                $result['errors'][] = "Erro ao registrar devolução para produto {$product->product_code}: "
                    . $this->stockMovementService->getMessage();
                continue;
            }

            $result['stock_movements']++;
        }

        $document->forceFill([
            'return_stock_processed_at' => now(),
            'return_stock_processed_by' => $userId,
        ])->save();

        Log::info('ProcessPurchaseReturnStockAction: processamento concluido', [
            'fiscal_document_id' => $document->id,
            'stock_movements' => $result['stock_movements'],
            'errors' => $result['errors'],
        ]);

        return $result;
    }
}
