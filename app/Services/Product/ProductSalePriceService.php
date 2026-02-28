<?php

namespace App\Services\Product;

use App\Enum\Product\OriginSalePrice;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Log;

/**
 * Resolve o preço de venda de um produto com base em sua configuração (origin_sale_price),
 * e nos dados de custo registrados no estoque (average_cost, last_cost).
 */
class ProductSalePriceService
{
    /**
     * Obtém o preço de venda calculado para o produto.
     *
     * Retorna null quando origin_sale_price = FREE (o preço é definido livremente pelo usuário).
     *
     * @param  Product           $product  Produto com origin_sale_price e profit_margin carregados
     * @param  ProductStock|null $stock    Registro de estoque (necessário para CALCULATED e CALCULATED_II)
     * @return float|null
     */
    public function resolve(int|Product $product, ?ProductStock $stock = null): ?float
    {
        if (is_int($product)) {
            $product = Product::with('stock')->find($product);
            if (! $product) {
                Log::warning('ProductSalePriceService: Produto não encontrado', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'product_id' => $product,
                ]);
                return null;
            }
        }

        $origin = $product->origin_sale_price;

        if ($origin === null) {
            Log::debug('ProductSalePriceService: origin_sale_price não definido, retornando null', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
            ]);
            return 0;
        }

        return match ($origin) {
            OriginSalePrice::FIXED        => $this->resolveFixed($product),
            OriginSalePrice::CALCULATED   => $this->resolveCalculated($product, $stock),
            OriginSalePrice::CALCULATED_II => $this->resolveCalculatedII($product, $stock),
            OriginSalePrice::FREE         => null,
        };
    }

    /**
     * Resolve o preço de venda para um produto pelo ID.
     * Carrega automaticamente o estoque da mesma empresa do produto.
     *
     * @param  int $productId
     * @return float|null
     */
    public function resolveById(int $productId): ?float
    {
        $product = Product::with('stock')->find($productId);

        if (! $product) {
            Log::warning('ProductSalePriceService: Produto não encontrado', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $productId,
            ]);
            return null;
        }

        return $this->resolve($product, $product->stock);
    }

    /**
     * Retorna o preço mínimo de venda configurado para um produto pelo ID.
     * Retorna 0 quando não há preço mínimo definido.
     *
     * @param  int $productId
     * @return float
     */
    public function getMinSalePriceById(int $productId): float
    {
        $product = Product::find($productId);

        if (! $product || ! $product->min_sale_price || $product->min_sale_price <= 0) {
            return 0;
        }

        return (float) $product->min_sale_price;
    }

    /**
     * FIXED: usa o valor fixo cadastrado no produto.
     */
    private function resolveFixed(Product $product): float
    {
        return (float) ($product->sale_price_value ?? 0);
    }

    /**
     * CALCULATED: Custo médio + margem de lucro.
     * Fórmula: average_cost × (1 + profit_margin / 100)
     */
    private function resolveCalculated(Product $product, ?ProductStock $stock): float
    {
        $cost   = (float) ($stock?->average_cost ?? 0);
        $margin = (float) ($product->profit_margin ?? 0);

        if ($cost === 0.0) {
            Log::debug('ProductSalePriceService: CALCULATED sem custo médio disponível', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
            ]);
        }

        return round($cost * (1 + $margin / 100), 4);
    }

    /**
     * CALCULATED_II: Último custo de compra + margem de lucro.
     * Fórmula: last_cost × (1 + profit_margin / 100)
     * Fallback para average_cost quando last_cost não estiver disponível.
     */
    private function resolveCalculatedII(Product $product, ?ProductStock $stock): float
    {
        $lastCost    = (float) ($stock?->last_cost ?? 0);
        $averageCost = (float) ($stock?->average_cost ?? 0);

        // Fallback para custo médio se o último custo não estiver disponível
        $cost   = $lastCost > 0 ? $lastCost : $averageCost;
        $margin = (float) ($product->profit_margin ?? 0);

        if ($cost === 0.0) {
            Log::debug('ProductSalePriceService: CALCULATED_II sem custo disponível (last_cost e average_cost zerados)', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
            ]);
        }

        return round($cost * (1 + $margin / 100), 4);
    }
}
