<?php

namespace App\Services\StockMovement\Actions;

use App\Enum\Product\Unit;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\Product\ProductUnitConversionService;
use InvalidArgumentException;

class PrepareStockMovementDataAction
{
    public function __construct(
        private readonly ProductUnitConversionService $conversionService,
    ) {
    }

    public function execute(array $data): array
    {
        $product = $this->resolveProduct($data);

        if (!$product) {
            throw new InvalidArgumentException('Produto não encontrado para preparar a movimentação de estoque.');
        }

        $baseUnit = $product->unit instanceof Unit
            ? $product->unit->value
            : (string) $product->unit;

        $operationalUnit = mb_strtoupper(trim((string) ($data['operational_unit'] ?? $baseUnit)));
        $operationalQuantity = (float) ($data['operational_quantity'] ?? $data['quantity'] ?? 0);

        $conversion = $this->conversionService->convertToBase($product, $operationalUnit, $operationalQuantity);

        $data['operational_unit'] = $conversion->operationalUnit;
        $data['operational_quantity'] = round($conversion->operationalQuantity, 3);
        $data['base_unit'] = $conversion->baseUnit;
        $data['base_quantity'] = round($conversion->baseQuantity, 3);
        $data['conversion_factor_snapshot'] = $conversion->factor;
        $data['quantity'] = $data['base_quantity'];

        return $data;
    }

    private function resolveProduct(array $data): ?Product
    {
        $productId = $data['product_id'] ?? null;

        if ($productId) {
            return Product::query()
                ->with('alternativeUnitConversions')
                ->find($productId);
        }

        $productStockId = $data['product_stock_id'] ?? null;

        if (!$productStockId) {
            return null;
        }

        $stock = ProductStock::query()
            ->with('product.alternativeUnitConversions')
            ->find($productStockId);

        return $stock?->product;
    }
}
