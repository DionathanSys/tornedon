<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Services\Product\ProductCodeService;
use App\Services\Product\Validators\ProductValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Notification\NotifyService as notify;
use App\Services\ProductTax\ProductTaxService;

class CreateProductAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo produto.
     *
     * @param array $data
     * @return Product|null
     */
    public function execute(array $data): ?Product
    {
        try {
            $validated = ProductValidator::validateCreate($data);

            if (empty($validated['product_code'])) {
                $validated['product_code'] = ProductCodeService::generate($validated['company_id']);
            }

            $validated['created_by'] = $this->createdBy;

            $product = Product::create($validated);

            // Sincroniza o estoque do produto se necessário
            if (isset($validated['has_stock_control'])) {
                $syncStockAction = new SyncProductStockAction($product, $this->createdBy);
                $syncStockAction->execute();

                if ($syncStockAction->hasError()) {
                    Log::warning($syncStockAction->getMessage(), [
                        'metodo'        => __METHOD__ . '@' . __LINE__,
                        'message'       => $syncStockAction->getMessage(),
                        'error_code'    => $syncStockAction->getErrorCode(),
                        'product_id'    => $product->id,
                        'error_message' => $syncStockAction->getMessage(),
                    ]);

                    notify::warning(
                        message: $syncStockAction->getMessage(),
                        toDatabase: true,
                        users: $this->createdBy,
                        errorCode: $syncStockAction->getErrorCode(),
                    );
                }
            }

            // Garante registro de tributos do produto
            try {
                $productTaxService = app(ProductTaxService::class);
                $productTax = $productTaxService->ensureForProduct($product->id, $this->createdBy, $data['tax'] ?? []);

                if ($productTaxService->hasError() || !$productTax) {
                    Log::warning('Não foi possível criar imposto de produto automaticamente', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'product_id' => $product->id,
                        'service_message' => $productTaxService->getMessage(),
                        'error_code' => $productTaxService->getErrorCode(),
                        'errors' => $productTaxService->getErrors(),
                    ]);

                    notify::warning(
                        message: $productTaxService->getMessage(),
                        toDatabase: true,
                        users: $this->createdBy,
                        errorCode: $productTaxService->getErrorCode(),
                    );
                    
                }
            } catch (\Exception $e) {
                $this->setError('Erro inesperado ao criar imposto de produto automaticamente');

                Log::error($this->getMessage(), [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'message' => $this->getMessage(),
                    'error_code' => $this->getErrorCode(),
                    'exception' => $e->getMessage(),
                    'user_id'    => $this->createdBy,
                    'product_id' => $product->id,
                ]);
            }

            $this->setSuccess();
            return $product;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000')
                ? 'Já existe um produto com este código'
                : 'Erro ao criar produto no banco de dados';

            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        }
    }
}
