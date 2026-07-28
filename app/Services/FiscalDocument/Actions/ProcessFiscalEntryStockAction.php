<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\StockMovement\Type as MovementType;
use App\Models\FiscalDocument;
use App\Models\ProductStock;
use App\Services\Product\ProductUnitConversionService;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class ProcessFiscalEntryStockAction
{
    public function __construct(
        private readonly StockMovementService $stockMovementService = new StockMovementService,
    ) {}

    /**
     * @return array{stock_movements: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, int $userId): array
    {
        $document->loadMissing(['items.product', 'items.product.stock']);

        $result = [
            'stock_movements' => 0,
            'errors' => [],
        ];

        foreach ($document->items as $item) {
            if (! $item->product_id) {
                $result['errors'][] = sprintf(
                    'Item %s sem produto vinculado. Confirme o vinculo antes de confirmar a nota.',
                    $item->item_number ?: '#'.$item->id,
                );

                Log::warning('ProcessFiscalEntryStockAction: Item sem produto', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'item' => $item,
                ]);

                continue;
            }

            $product = $item->product;
            if (! $product || ! $product->has_stock_control) {
                Log::info('ProcessFiscalEntryStockAction: Produto sem controle de estoque', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'product' => $product,
                ]);

                continue;
            }

            $stock = ProductStock::query()
                ->where('product_id', $item->product_id)
                ->where('company_id', $document->company_id)
                ->first();

            if (! $stock) {
                $result['errors'][] = "Produto #{$product->product_code} sem estoque cadastrado.";

                Log::warning('ProcessFiscalEntryStockAction: ProductStock não encontrado', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'product_id' => $item->product_id,
                    'company_id' => $document->company_id,
                ]);

                continue;
            }

            $movementData = $this->resolveMovementData($product, $item, $stock->id, $document->company_id);

            if ($movementData === null) {
                $invalidUnit = (string) ($item->unit_of_measure ?: $item->taxable_unit ?: $product->unit?->value);
                $result['errors'][] = "Produto {$product->product_code} com unidade {$invalidUnit} não cadastrada.";

                Log::warning('ProcessFiscalEntryStockAction: Unidade não cadastrada para o produto', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'product_id' => $item->product_id,
                    'product_code' => $product->product_code,
                    'operational_unit' => $invalidUnit,
                    'fiscal_document_id' => $document->id,
                ]);

                continue;
            }

            $movement = $this->stockMovementService->create(array_merge($movementData, [
                'type' => MovementType::ENTRY->value,
                'unit_price' => (float) $item->unit_price,
                'reason' => "Nota de Entrada #{$document->document_number} - Produto: {$product->product_code}",
                'source_type' => 'fiscal_document',
                'source_id' => $document->id,
            ]), $userId);

            if ($this->stockMovementService->hasError() || ! $movement) {
                $result['errors'][] = "Erro ao registrar movimentação para produto {$product->product_code}: "
                    .$this->stockMovementService->getMessage();

                continue;
            }

            $result['stock_movements']++;
        }

        return $result;
    }

    private function resolveMovementData(mixed $product, mixed $item, int $stockId, int $companyId): ?array
    {
        $conversionService = app(ProductUnitConversionService::class);
        $commercialUnit = (string) ($item->unit_of_measure ?? '');
        $commercialQuantity = (float) ($item->quantity ?? 0);
        $taxableUnit = (string) ($item->taxable_unit ?? '');
        $taxableQuantity = (float) ($item->taxable_quantity ?? 0);

        if (
            $commercialUnit !== ''
            && $commercialQuantity > 0
            && $taxableUnit !== ''
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
