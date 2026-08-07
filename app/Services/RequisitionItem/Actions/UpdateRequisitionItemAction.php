<?php

namespace App\Services\RequisitionItem\Actions;

use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Services\Product\ProductUnitConversionService;
use App\Services\RequisitionItem\Validators\RequisitionItemValidator;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateRequisitionItemAction
{
    use HandlesActionResponse, AuthorizesRequisitionItemActions;

    public function __construct(
        private int             $updatedBy,
        private RequisitionItem $requisitionItem,
    ) {}

    /**
     * Atualiza um item de requisição existente.
     *
     * @param array $data
     * @return RequisitionItem|null
     */
    public function execute(array $data): ?RequisitionItem
    {
        if (! self::canModifyItems($this->requisitionItem->requisition_id)) {
            $this->setError('Não é permitido atualizar itens desta requisição.');
            return null;
        }

        // Captura os valores atuais antes da atualização (necessário para o evento)
        $oldProductId = (int) $this->requisitionItem->product_id;
        $oldUnitOfMeasure = (string) ($this->requisitionItem->unit_of_measure ?? '');
        $oldQuantity  = (float) $this->requisitionItem->quantity;
        $oldBaseQuantity = $this->requisitionItem->resolvedBaseQuantity();
        $oldUnitPrice = (float) $this->requisitionItem->unit_price;

        // Validação de saldo disponível no estoque
        $stockError = $this->validateStockAvailability($data, $oldProductId, $oldQuantity);
        if ($stockError) {
            $this->setError($stockError);
            return null;
        }

        // Valida preço mínimo de venda
        $productIdForPrice = isset($data['product_id']) ? (int) $data['product_id'] : $oldProductId;
        if ($productIdForPrice && isset($data['unit_price'])) {
            $priceError = $this->validateMinSalePrice($productIdForPrice, (float) $data['unit_price']);
            if ($priceError) {
                $this->setError($priceError);
                return null;
            }
        }

        try {
            $validated = RequisitionItemValidator::validateUpdate($data);
            $validated = $this->applyBaseQuantitySnapshot($validated);
            $validated = $this->applyUnitCostSnapshot($validated);

            $validated['updated_by'] = $this->updatedBy;

            $this->requisitionItem->update($validated);
            $this->requisitionItem->refresh();
            $this->requisitionItem->load('product');

            RequisitionItemUpdated::dispatch(
                $this->requisitionItem,
                $oldProductId,
                $oldUnitOfMeasure,
                $oldQuantity,
                $oldBaseQuantity,
                $oldUnitPrice,
                $this->updatedBy,
            );

            $this->setSuccess();
            return $this->requisitionItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'errors'              => $e->errors(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return null;
        }
    }

    /**
     * Verifica se o preço unitário respeita o preço mínimo de venda do produto.
     * Retorna null se OK, ou mensagem de erro caso contrário.
     */
    private function validateMinSalePrice(int $productId, float $unitPrice): ?string
    {
        $product = Product::find($productId);

        if (! $product || ! $product->min_sale_price || $product->min_sale_price <= 0) {
            return null;
        }

        $minPrice = (float) $product->min_sale_price;

        if ($unitPrice < $minPrice) {
            return sprintf(
                'O preço unitário (R$ %s) está abaixo do preço mínimo de venda de "%s" (R$ %s).',
                number_format($unitPrice, 2, ',', '.'),
                $product->name,
                number_format($minPrice, 2, ',', '.')
            );
        }

        return null;
    }

    /**
     * Verifica saldo disponível levando em conta o delta de quantidade/produto.
     *
     * - Mesmo produto: valida apenas o delta (nova qty - old qty)
     * - Produto mudou: valida a quantidade total no novo produto
     */
    private function validateStockAvailability(array $data, int $oldProductId, float $oldQuantity): ?string
    {
        $newProductId = isset($data['product_id']) ? (int) $data['product_id'] : $oldProductId;
        $newQuantity  = isset($data['quantity']) ? (float) $data['quantity'] : null;
        $newUnit      = $data['unit_of_measure'] ?? $this->requisitionItem->unit_of_measure;
        $oldUnit      = $this->requisitionItem->unit_of_measure;

        if ($newQuantity === null && $newProductId === $oldProductId) {
            return null; // Nem produto nem quantidade mudaram
        }

        $productId = $newProductId;

        $product = Product::with('stock')->find($productId);

        if (! $product || ! $product->has_stock_control) {
            return null;
        }

        /** @var ProductStock|null $stock */
        $stock = $product->stock;

        if (! $stock || $stock->allow_negative) {
            return null;
        }

        $conversionService = app(ProductUnitConversionService::class);
        $newBaseQuantity = $conversionService
            ->convertToBase($product, (string) ($newUnit ?: $product->unit?->value), (float) ($newQuantity ?? $oldQuantity))
            ->baseQuantity;

        if ($newProductId !== $oldProductId) {
            $quantityNeeded = $newBaseQuantity;
        } else {
            $oldBaseQuantity = $conversionService
                ->convertToBase($product, (string) ($oldUnit ?: $product->unit?->value), $oldQuantity)
                ->baseQuantity;

            $quantityNeeded = $newBaseQuantity - $oldBaseQuantity;
        }

        if ($quantityNeeded <= 0) {
            return null; // Redução de quantidade ou sem impacto no estoque
        }

        // quantity_available é coluna virtual: quantity_total - quantity_reserved
        $netAvailable = (float) $stock->quantity_available;

        if ($netAvailable < $quantityNeeded) {
            $label = $newProductId !== $oldProductId
                ? 'Saldo insuficiente para o novo produto'
                : 'Saldo insuficiente para a quantidade adicional';

            return sprintf(
                '%s "%s". Disponível: %s, Necessário: %s.',
                $label,
                $product->name,
                number_format($netAvailable, 3, ',', '.'),
                number_format($quantityNeeded, 3, ',', '.')
            );
        }

        return null;
    }

    private function applyBaseQuantitySnapshot(array $validated): array
    {
        $productId = (int) ($validated['product_id'] ?? $this->requisitionItem->product_id);

        if ($productId < 1) {
            return $validated;
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (!$product) {
            return $validated;
        }

        $unit = (string) ($validated['unit_of_measure'] ?? $this->requisitionItem->unit_of_measure ?? $product->unit?->value);
        $quantity = (float) ($validated['quantity'] ?? $this->requisitionItem->quantity ?? 0);

        $conversion = app(ProductUnitConversionService::class)->convertToBase($product, $unit, $quantity);

        $validated['quantity_in_base_unit'] = round($conversion->baseQuantity, 8);
        $validated['conversion_factor_snapshot'] = $conversion->factor;

        return $validated;
    }

    private function applyUnitCostSnapshot(array $validated): array
    {
        if (array_key_exists('unit_cost', $validated) && $validated['unit_cost'] !== null && (float) $validated['unit_cost'] > 0) {
            return $validated;
        }

        if (! array_key_exists('product_id', $validated) && (float) ($this->requisitionItem->unit_cost ?? 0) > 0) {
            return $validated;
        }

        $productId = (int) ($validated['product_id'] ?? $this->requisitionItem->product_id ?? 0);

        if ($productId < 1) {
            return $validated;
        }

        $product = Product::query()->find($productId, ['id', 'company_id']);

        if (! $product) {
            return $validated;
        }

        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('company_id', $product->company_id)
            ->first(['average_cost', 'last_cost']);

        $cost = (float) ($stock?->average_cost ?: $stock?->last_cost ?: 0);

        if ($cost > 0) {
            $validated['unit_cost'] = $cost;
        }

        return $validated;
    }
}
