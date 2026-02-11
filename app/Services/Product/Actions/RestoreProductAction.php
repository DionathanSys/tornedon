<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreProductAction
{
    use HandlesActionResponse;

    public function __construct(
        private Product $product,
    ) {}

    /**
     * Restaura um produto excluído (soft delete).
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            // Valida se pode restaurar
            if (!$this->validateCanRestore()) {
                return false;
            }

            // Restaura o produto
            $result = $this->product->restore();

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            // Erro de duplicação
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Já existe um produto ativo com este código',
                    ['product_code' => ['Código duplicado']]
                );
            } else {
                $this->setError('Erro ao restaurar produto', ['database' => [$e->getMessage()]]);
            }

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro de query ao restaurar produto',
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'product_id' => $this->product->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao restaurar produto', ['error' => [$e->getMessage()]]);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro inesperado ao restaurar produto',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $this->product->id,
            ]);

            return false;
        }
    }

    /**
     * Valida se o produto pode ser restaurado.
     *
     * @return bool
     */
    private function validateCanRestore(): bool
    {
        // Verifica se o produto está realmente excluído
        if (!$this->product->trashed()) {
            $this->setError(
                'Este produto não está excluído',
                ['product' => ['Produto não está excluído']]
            );
            return false;
        }

        // Verifica se já existe um produto ativo com o mesmo código
        $duplicateProduct = DB::table('products')
            ->where('product_code', $this->product->product_code)
            ->where('company_id', $this->product->company_id)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicateProduct) {
            $this->setError(
                'Já existe um produto ativo com o código ' . $this->product->product_code,
                ['product_code' => ['Código já existe para outro produto ativo']]
            );
            return false;
        }

        return true;
    }
}
