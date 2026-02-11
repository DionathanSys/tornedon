<?php

namespace App\Services\ProductStock\Actions;

use App\Models\ProductStock;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteProductStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private ProductStock $productStock,
    ) {}

    /**
     * Exclui (soft delete) um registro de estoque.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            if (!$this->validateCanDelete()) {
                return false;
            }

            $result = $this->productStock->delete();

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir este produto do estoque pois ele possui vínculos com outros registros',
                    ['product_stock' => ['Produto do estoque vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir produto do estoque');
            }

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'sql_code'          => $e->getCode(),
                'product_stock_id'  => $this->productStock->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir produto do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $this->productStock->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um registro de produto do estoque.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            if (!$this->validateCanForceDelete()) {
                return false;
            }

            $result = $this->productStock->forceDelete();

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir permanentemente este produto do estoque pois ele possui vínculos com outros registros',
                    ['product_stock' => ['Produto do estoque vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir permanentemente produto do estoque');
            }

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'sql_code'          => $e->getCode(),
                'product_stock_id'  => $this->productStock->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente produto do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $this->productStock->id,
            ]);

            return false;
        }
    }

    /**
     * Valida se o estoque pode ser excluído (soft delete).
     *
     * @return bool
     */
    private function validateCanDelete(): bool
    {
        // Verifica se o produto do estoque já está excluído
        if ($this->productStock->trashed()) {
            $this->setError(
                'Este produto do estoque já está excluído',
                ['product_stock' => ['Produto do estoque já excluído']]
            );
            return false;
        }

        // Verifica se há quantidades disponíveis ou reservadas
        if ($this->productStock->quantity_available > 0) {
            $this->setError(
                'Não é possível excluir este produto do estoque pois há quantidade disponível',
                ['product_stock' => ['Produto do estoque possui quantidade disponível: ' . $this->productStock->quantity_available]]
            );
            return false;
        }

        if ($this->productStock->quantity_reserved > 0) {
            $this->setError(
                'Não é possível excluir este produto do estoque pois há quantidade reservada',
                ['product_stock' => ['Produto do estoque possui quantidade reservada: ' . $this->productStock->quantity_reserved]]
            );
            return false;
        }

        return true;
    }

    /**
     * Valida se o estoque pode ser excluído permanentemente.
     *
     * @return bool
     */
    private function validateCanForceDelete(): bool
    {
        // Verifica se o produto do estoque não está marcado como excluído
        if (!$this->productStock->trashed()) {
            $this->setError(
                'O produto do estoque deve estar excluído antes de ser removido permanentemente',
                ['product_stock' => ['Produto do estoque não está excluído']]
            );
            return false;
        }

        return true;
    }
}
