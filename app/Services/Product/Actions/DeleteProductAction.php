<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Services\ProductStock\ProductStockService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteProductAction
{
    use HandlesActionResponse;

    private ProductStockService $stockService;

    public function __construct(
        private Product $product,
    ) {
        $this->stockService = new ProductStockService();
    }

    /**
     * Exclui (soft delete) um produto.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            // Valida se pode excluir
            if (!$this->validateCanDelete()) {
                return false;
            }

            // Exclui o estoque associado (soft delete)
            if ($this->product->stock) {
                $stockDeleted = $this->stockService->delete($this->product->stock);
                if (!$stockDeleted) {
                    $this->setError(
                        'Erro ao excluir o estoque do produto',
                        $this->stockService->getErrors(),
                        422
                    );
                    return false;
                }
            }

            // Exclui o produto
            $result = $this->product->delete();

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            // Erro de constraint de chave estrangeira
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir este produto pois ele possui vínculos com outros registros',
                    ['product' => ['Produto vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir produto');
            }

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'product_id' => $this->product->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $this->product->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um produto.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            // Valida se pode excluir permanentemente
            if (!$this->validateCanForceDelete()) {
                return false;
            }

            // Exclui permanentemente o estoque associado
            $stock = $this->product->stock()->withTrashed()->first();
            if ($stock) {
                $stockDeleted = $this->stockService->forceDelete($stock);
                if (!$stockDeleted) {
                    $this->setError(
                        'Erro ao excluir permanentemente o estoque do produto',
                        $this->stockService->getErrors(),
                        422
                    );
                    return false;
                }
            }

            // Exclui permanentemente
            $result = $this->product->forceDelete();

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            // Erro de constraint de chave estrangeira
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir permanentemente este produto pois ele possui vínculos com outros registros',
                    ['product' => ['Produto vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir permanentemente produto');
            }

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'product_id' => $this->product->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $this->product->id,
            ]);

            return false;
        }
    }

    /**
     * Valida se o produto pode ser excluído (soft delete).
     *
     * @return bool
     */
    private function validateCanDelete(): bool
    {
        // Verifica se o produto já está excluído
        if ($this->product->trashed()) {
            $this->setError(
                'Este produto já está excluído',
                ['product' => ['Produto já excluído']]
            );
            return false;
        }

        // Verifica se existem itens de requisição vinculados
        $hasRequisitionItems = DB::table('requisition_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasRequisitionItems) {
            $this->setError(
                'Não é possível excluir este produto pois existem requisições vinculadas a ele',
                ['product' => ['Produto possui requisições vinculadas']]
            );
            return false;
        }

        // Verifica se existem itens de cotação vinculados
        $hasQuoteItems = DB::table('quote_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasQuoteItems) {
            $this->setError(
                'Não é possível excluir este produto pois existem cotações vinculadas a ele',
                ['product' => ['Produto possui cotações vinculadas']]
            );
            return false;
        }

        // Verifica se existem itens de ordem de produção vinculados
        $hasProductionOrderItems = DB::table('production_order_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasProductionOrderItems) {
            $this->setError(
                'Não é possível excluir este produto pois existem ordens de produção vinculadas a ele',
                ['product' => ['Produto possui ordens de produção vinculadas']]
            );
            return false;
        }

        return true;
    }

    /**
     * Valida se o produto pode ser excluído permanentemente.
     *
     * @return bool
     */
    private function validateCanForceDelete(): bool
    {
        // Verifica se o produto não está marcado como excluído
        if (!$this->product->trashed()) {
            $this->setError(
                'O produto deve estar excluído antes de ser removido permanentemente',
                ['product' => ['Produto não está excluído']]
            );
            return false;
        }

        // Verifica se existem itens de requisição vinculados
        $hasRequisitionItems = DB::table('requisition_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasRequisitionItems) {
            $this->setError(
                'Não é possível excluir permanentemente este produto pois existem requisições vinculadas a ele',
                ['product' => ['Produto possui requisições vinculadas']]
            );
            return false;
        }

        // Verifica se existem itens de cotação vinculados
        $hasQuoteItems = DB::table('quote_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasQuoteItems) {
            $this->setError(
                'Não é possível excluir permanentemente este produto pois existem cotações vinculadas a ele',
                ['product' => ['Produto possui cotações vinculadas']]
            );
            return false;
        }

        // Verifica se existem itens de ordem de produção vinculados
        $hasProductionOrderItems = DB::table('production_order_items')
            ->where('product_id', $this->product->id)
            ->exists();

        if ($hasProductionOrderItems) {
            $this->setError(
                'Não é possível excluir permanentemente este produto pois existem ordens de produção vinculadas a ele',
                ['product' => ['Produto possui ordens de produção vinculadas']]
            );
            return false;
        }

        return true;
    }
}
