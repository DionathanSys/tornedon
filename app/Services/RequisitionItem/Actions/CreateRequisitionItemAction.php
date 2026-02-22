<?php

namespace App\Services\RequisitionItem\Actions;

use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Services\RequisitionItem\Validators\RequisitionItemValidator;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateRequisitionItemAction
{
    use HandlesActionResponse, AuthorizesRequisitionItemActions;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo item de requisição.
     *
     * @param array $data
     * @return RequisitionItem|null
     */
    public function execute(array $data): ?RequisitionItem
    {
        if (! isset($data['requisition_id']) || ! self::canModifyItems($data['requisition_id'])) {
            $this->setError('Não é permitido adicionar itens a esta requisição.');
            return null;
        }

        // Validação de saldo disponível no estoque
        if (isset($data['product_id']) && isset($data['quantity'])) {
            $stockError = $this->validateStockAvailability(
                (int) $data['product_id'],
                (float) $data['quantity']
            );

            if ($stockError) {
                $this->setError($stockError);
                return null;
            }
        }

        try {
            $validated = RequisitionItemValidator::validateCreate($data);

            $validated['created_by'] = $this->createdBy;

            $item = RequisitionItem::create($validated);

            // Carrega o produto para o evento ser processado corretamente
            $item->load('product');

            RequisitionItemCreated::dispatch($item, $this->createdBy);

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
            $this->setError('Erro ao criar item da requisição');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item da requisição');

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

    /**
     * Verifica se existe saldo disponível no estoque para a quantidade solicitada.
     * Retorna null se OK, ou uma mensagem de erro caso não haja saldo.
     */
    private function validateStockAvailability(int $productId, float $requestedQty): ?string
    {
        $product = Product::find($productId);

        if (! $product || ! $product->has_stock_control) {
            return null;
        }

        $stock = ProductStock::where('product_id', $productId)->first();

        if (! $stock) {
            return null;
        }

        if ($stock->allow_negative) {
            return null;
        }

        $netAvailable = $stock->quantity_available - $stock->quantity_reserved;

        if ($netAvailable < $requestedQty) {
            return "Saldo insuficiente no estoque. Disponível: {$netAvailable}, Solicitado: {$requestedQty}";
        }

        return null;
    }
}
