<?php

namespace App\Services\ProductionOrderItem\Actions;

use App\Models\Product;
use App\Models\ProductionOrderItem;
use App\Services\Product\ProductUnitConversionService;
use App\Services\ProductionOrderItem\Validators\ProductionOrderItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductionOrderItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private ProductionOrderItem $productionOrderItem,
    ) {}

    /**
     * Atualiza um item de ordem de produção existente.
     *
     * @param array $data
     * @return ProductionOrderItem|null
     */
    public function execute(array $data): ?ProductionOrderItem
    {
        try {
            $validated = ProductionOrderItemValidator::validateUpdate($data);
            $validated = $this->applyBaseQuantitySnapshot($validated);

            $this->productionOrderItem->update($validated);
            $this->productionOrderItem->refresh();

            $this->setSuccess();
            return $this->productionOrderItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'errors'                    => $e->errors(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da ordem de produção');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'sql'                       => $e->getSql(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'trace'                     => $e->getTraceAsString(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;
        }
    }

    private function applyBaseQuantitySnapshot(array $validated): array
    {
        $productId = (int) ($validated['product_id'] ?? $this->productionOrderItem->product_id);

        if ($productId < 1) {
            $validated['quantity_in_base_unit'] = (float) ($validated['quantity'] ?? $this->productionOrderItem->quantity ?? 0);
            $validated['quantity_approved_in_base_unit'] = (float) ($validated['quantity_approved'] ?? $this->productionOrderItem->quantity_approved ?? 0);
            $validated['conversion_factor_snapshot'] = 1;

            return $validated;
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (!$product) {
            return $validated;
        }

        $unit = (string) ($validated['unit_of_measure'] ?? $this->productionOrderItem->unit_of_measure ?? $product->unit?->value);
        $quantity = (float) ($validated['quantity'] ?? $this->productionOrderItem->quantity ?? 0);
        $approvedQuantity = (float) ($validated['quantity_approved'] ?? $this->productionOrderItem->quantity_approved ?? 0);

        $quantityConversion = app(ProductUnitConversionService::class)
            ->convertToBase($product, $unit, $quantity);

        $validated['quantity_in_base_unit'] = round($quantityConversion->baseQuantity, 8);
        $validated['quantity_approved_in_base_unit'] = round($approvedQuantity * $quantityConversion->factor, 8);
        $validated['conversion_factor_snapshot'] = $quantityConversion->factor;

        return $validated;
    }
}
