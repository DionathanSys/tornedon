<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Models\ProductStock;
use App\Services\ProductStock\ProductStockService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class SyncProductStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private Product $product,
        private int $userId,
    ) {}

    /**
     * Sincroniza o estoque do produto com base no has_stock_control.
     * Cria se necessário se não existir, ou exclui se existir e não for mais necessário.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            $productStockService = app(ProductStockService::class);
            
            $existingStock = ProductStock::where('product_id', $this->product->id)
                ->withTrashed()
                ->first();

            // Se o produto deve ter controle de estoque
            if ($this->product->has_stock_control) {
                if (!$existingStock) {
                    // Cria novo registro de estoque usando o service
                    $stockData = [
                        'product_id'            => $this->product->id,
                        'quantity_total'        => 0,
                        'quantity_reserved'     => 0,
                        'quantity_minimum'      => 0,
                        'quantity_maximum'      => null,
                        'average_cost'          => 0,
                        'last_cost'             => null,
                        'last_sale_price'       => null,
                        'last_movement_date'    => null,
                        'last_movement_type'    => null,
                        'is_active'             => true,
                        'allow_negative'        => false,
                        'additional_info'       => null,
                        'company_id'            => $this->product->company_id,
                    ];

                    $productStock = $productStockService->create($stockData, $this->userId);

                    if ($productStockService->hasError() || !$productStock) {
                        $this->setError(
                            'Erro ao criar estoque automaticamente',
                            $productStockService->getErrors(),
                            422,
                            $productStockService->getErrorCode()
                        );

                        Log::error($this->getMessage(), [
                            'metodo'            => __METHOD__ . '@' . __LINE__,
                            'message'           => $this->getMessage(),
                            'error_code'        => $this->getErrorCode(),
                            'service_message'   => $productStockService->getMessage(),
                            'product_id'        => $this->product->id,
                        ]);

                        return false;
                    }

                    Log::info('Estoque criado automaticamente para o produto', [
                        'product_id'    => $this->product->id,
                        'product_code'  => $this->product->product_code,
                        'stock_id'      => $productStock->id,
                        'user_id'       => $this->userId,
                    ]);

                } elseif ($existingStock->trashed()) {
                    // Restaura o estoque se estava excluído usando o service
                    $restored = $productStockService->restore($existingStock);

                    if ($productStockService->hasError() || !$restored) {
                        $this->setError(
                            'Erro ao restaurar estoque automaticamente',
                            $productStockService->getErrors(),
                            422,
                            $productStockService->getErrorCode()
                        );

                        Log::error($this->getMessage(), [
                            'metodo'            => __METHOD__ . '@' . __LINE__,
                            'message'           => $this->getMessage(),
                            'error_code'        => $this->getErrorCode(),
                            'service_message'   => $productStockService->getMessage(),
                            'product_id'        => $this->product->id,
                            'stock_id'          => $existingStock->id,
                        ]);

                        return false;
                    }

                    Log::info('Estoque restaurado automaticamente para o produto', [
                        'product_id'    => $this->product->id,
                        'product_code'  => $this->product->product_code,
                        'stock_id'      => $existingStock->id,
                        'user_id'       => $this->userId,
                    ]);
                }
            } else {
                // Se o produto não deve ter controle de estoque e existe um registro
                if ($existingStock && !$existingStock->trashed()) {
                    $deleted = $productStockService->delete($existingStock);

                    if ($productStockService->hasError() || !$deleted) {
                        // Se houver erro (ex: quantidade disponível > 0), registra mas não falha
                        Log::warning($productStockService->getMessage(), [
                            'metodo'            => __METHOD__ . '@' . __LINE__,
                            'service_message'   => $productStockService->getMessage(),
                            'error_code'        => $productStockService->getErrorCode(),
                            'product_id'        => $this->product->id,
                            'stock_id'          => $existingStock->id,
                        ]);

                        notify::warning(
                            message: $productStockService->getMessage(),
                            toDatabase: true,
                            users: $this->userId,
                        );

                        // Não retorna false aqui, pois o produto foi salvo com sucesso
                        // apenas o estoque não pôde ser excluído por ter quantidades
                        $this->setSuccess();
                        return true;
                    }

                    Log::info('Estoque excluído automaticamente para o produto', [
                        'product_id'    => $this->product->id,
                        'product_code'  => $this->product->product_code,
                        'stock_id'      => $existingStock->id,
                        'user_id'       => $this->userId,
                    ]);
                }
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao sincronizar estoque do produto');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'exception'     => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'product_id'    => $this->product->id,
            ]);

            return false;
        }
    }
}
