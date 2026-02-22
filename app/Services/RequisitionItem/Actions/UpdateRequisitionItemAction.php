<?php

namespace App\Services\RequisitionItem\Actions;

use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
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
        $oldQuantity  = (float) $this->requisitionItem->quantity;
        $oldUnitPrice = (float) $this->requisitionItem->unit_price;

        // Validação de saldo disponível no estoque
        $stockError = $this->validateStockAvailability($data, $oldProductId, $oldQuantity);
        if ($stockError) {
            $this->setError($stockError);
            return null;
        }

        try {
            $validated = RequisitionItemValidator::validateUpdate($data);

            $validated['updated_by'] = $this->updatedBy;

            $this->requisitionItem->update($validated);
            $this->requisitionItem->refresh();
            $this->requisitionItem->load('product');

            RequisitionItemUpdated::dispatch(
                $this->requisitionItem,
                $oldProductId,
                $oldQuantity,
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
     * Verifica saldo disponível levando em conta o delta de quantidade/produto.
     *
     * - Mesmo produto: valida apenas o delta (nova qty - old qty)
     * - Produto mudou: valida a quantidade total no novo produto
     */
    private function validateStockAvailability(array $data, int $oldProductId, float $oldQuantity): ?string
    {
        $newProductId = isset($data['product_id']) ? (int) $data['product_id'] : $oldProductId;
        $newQuantity  = isset($data['quantity']) ? (float) $data['quantity'] : null;

        if ($newQuantity === null && $newProductId === $oldProductId) {
            return null; // Nem produto nem quantidade mudaram
        }

        $productId      = $newProductId;
        $quantityNeeded = ($newProductId !== $oldProductId)
            ? ($newQuantity ?? $oldQuantity)               // Produto novo: valida quantidade inteira
            : ($newQuantity ?? $oldQuantity) - $oldQuantity; // Mesmo produto: valida só o delta

        if ($quantityNeeded <= 0) {
            return null; // Redução de quantidade ou sem impacto no estoque
        }

        $product = Product::with('stock')->find($productId);

        if (! $product || ! $product->has_stock_control) {
            return null;
        }

        /** @var ProductStock|null $stock */
        $stock = $product->stock;

        if (! $stock || $stock->allow_negative) {
            return null;
        }

        $netAvailable = (float) $stock->quantity_available - (float) $stock->quantity_reserved;

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
}
