<?php

namespace App\Services\ProductTax;

use App\Models\ProductTax;
use App\Services\ProductTax\Actions\CreateProductTaxAction;
use App\Services\ProductTax\Actions\UpdateProductTaxAction;
use App\Services\ProductTax\Validators\ProductTaxValidator;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class ProductTaxService
{
    use HandlesServiceResponse;

    /**
     * Cria um registro de product_tax ou retorna o existente.
     * Garante que não haverá duplicidade e que sempre haverá um registro vinculado ao produto.
     *
     * @param int $productId
     * @param int $createdBy
     * @param array $data
     * @return ProductTax|null
     */
    public function ensureForProduct(int $productId, int $createdBy, array $data = []): ?ProductTax
    {
        try {
            $existing = ProductTax::where('product_id', $productId)->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                    $this->setSuccess('Imposto de Produto restaurado');
                } else {
                    $this->setSuccess('Imposto de Produto existente');
                }

                return $existing;
            }

            $action = new CreateProductTaxAction($productId, $createdBy);
            $result = $action->execute($data);

            if ($action->hasError() || !$result) {
                $this->setError($action->getMessage(), $action->getErrors(), 422, $action->getErrorCode());
                Log::error($action->getMessage(), [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'action_message'    => $action->getMessage(),
                    'error_code'        => $action->getErrorCode(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Imposto de Produto criado com sucesso');
            return $result;

        } catch (\Exception $e) {
            $this->setError('Erro ao garantir imposto de produto');
            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'exception'     => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'product_id'    => $productId,
            ]);
            return null;
        }
    }

    /**
     * Atualiza os dados de tributos de um produto.
     * Se não existir registro, cria (ensureForProduct).
     *
     * @param int $productId
     * @param int $updatedBy
     * @param array $data
     * @return ProductTax|null
     */
    public function update(int $productId, int $updatedBy, array $data = []): ?ProductTax
    {
        $this->resetResponse();

        try {
            $existing = ProductTax::where('product_id', $productId)->first();

            // Se não existir, garante a criação
            if (!$existing) {
                Log::info('Nenhum registro de imposto encontrado para o produto. Criando um novo.', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'product_id' => $productId,
                ]);

                return $this->ensureForProduct($productId, $updatedBy, $data);
            }

            $action = new UpdateProductTaxAction($existing, $updatedBy);
            $result = $action->execute($data);

            if ($action->hasError() || !$result) {
                $this->setError($action->getMessage(), $action->getErrors(), 422, $action->getErrorCode());
                Log::error($action->getMessage(), [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'action_message'    => $action->getMessage(),
                    'error_code'        => $action->getErrorCode(),
                    'errors'            => $action->getErrors(),
                    'product_id'        => $productId,
                ]);
                return null;
            }

            $this->setSuccess('Imposto de Produto atualizado com sucesso');
            return $result;

        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar imposto de produto');
            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'exception'     => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'product_id'    => $productId,
            ]);
            return null;
        }
    }
}
