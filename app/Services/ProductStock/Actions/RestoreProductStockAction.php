<?php

namespace App\Services\ProductStock\Actions;

use App\Models\ProductStock;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class RestoreProductStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private ProductStock $productStock,
    ) {}

    /**
     * Restaura um registro de produto do estoque excluído.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            if (!$this->productStock->trashed()) {
                $this->setError('O produto do estoque não está excluído');

                Log::warning($this->getMessage(), [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'message'           => $this->getMessage(),
                    'error_code'        => $this->getErrorCode(),
                    'product_stock_id'  => $this->productStock->id,
                ]);

                return false;
            }

            $result = $this->productStock->restore();

            if (!$result) {
                $this->setError('Não foi possível restaurar o produto do estoque');

                Log::error($this->getMessage(), [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'message'           => $this->getMessage(),
                    'error_code'        => $this->getErrorCode(),
                    'product_stock_id'  => $this->productStock->id,
                ]);

                return false;
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao restaurar produto do estoque');

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
}
