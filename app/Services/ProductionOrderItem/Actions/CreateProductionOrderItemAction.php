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

class CreateProductionOrderItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo item de ordem de produção.
     *
     * @param array $data
     * @return ProductionOrderItem|null
     */
    public function execute(array $data): ?ProductionOrderItem
    {
        try {
            $validated = ProductionOrderItemValidator::validateCreate($data);
            $validated = $this->applyBaseQuantitySnapshot($validated);

            $item = ProductionOrderItem::create($validated);

            $this->setSuccess();
            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao criar item da ordem de produção');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql'        => $e->getSql(),
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }

    private function applyBaseQuantitySnapshot(array $validated): array
    {
        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($validated['product_id'] ?? null);

        if (!$product) {
            $validated['quantity_in_base_unit'] = (float) ($validated['quantity'] ?? 0);
            $validated['quantity_approved_in_base_unit'] = (float) ($validated['quantity_approved'] ?? 0);
            $validated['conversion_factor_snapshot'] = 1;

            return $validated;
        }

        $unit = (string) ($validated['unit_of_measure'] ?? $product->unit?->value);
        $quantity = (float) ($validated['quantity'] ?? 0);
        $approvedQuantity = (float) ($validated['quantity_approved'] ?? 0);

        $quantityConversion = app(ProductUnitConversionService::class)
            ->convertToBase($product, $unit, $quantity);

        $validated['quantity_in_base_unit'] = round($quantityConversion->baseQuantity, 8);
        $validated['quantity_approved_in_base_unit'] = round($approvedQuantity * $quantityConversion->factor, 8);
        $validated['conversion_factor_snapshot'] = $quantityConversion->factor;

        return $validated;
    }
}
