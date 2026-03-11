<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Services\Product\Validators\ProductValidator;
use App\Services\ProductTax\ProductTaxService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private Product $product,
    ) {}

    /**
     * Atualiza um produto existente.
     *
     * @param array $data
     * @return Product|null
     */
    public function execute(array $data): ?Product
    {
        try {
            // Validacao
            $validated = ProductValidator::validateUpdate($data, $this->product->id, $this->product->company_id);
            $hasAlternativeUnitConversions = array_key_exists('alternative_unit_conversions', $validated);
            $alternativeUnitConversions = $validated['alternative_unit_conversions'] ?? [];

            // Remove campos que nao devem ser atualizados
            unset($validated['product_code'], $validated['company_id'], $validated['alternative_unit_conversions']);

            $validated['updated_by'] = $this->updatedBy;

            // Persistencia
            $this->product->update($validated);

            if ($hasAlternativeUnitConversions) {
                $this->syncAlternativeUnitConversions($alternativeUnitConversions);
            }

            Log::debug('Produto atualizado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'product' => $this->product->refresh(),
            ]);

            // Sincroniza o estoque do produto se o campo has_stock_control foi atualizado
            if (isset($validated['has_stock_control'])) {
                $this->product->refresh();
                $syncStockAction = new SyncProductStockAction($this->product, $this->updatedBy);
                $syncStockAction->execute();

                if ($syncStockAction->hasError()) {
                    Log::warning('Erro ao sincronizar estoque durante atualizacao do produto', [
                        'metodo'        => __METHOD__ . '@' . __LINE__,
                        'product_id'    => $this->product->id,
                        'error_message' => $syncStockAction->getMessage(),
                    ]);
                }

                Log::debug('Sincronizacao de estoque executada durante atualizacao do produto', [
                    'metodo'        => __METHOD__ . '@' . __LINE__,
                    'product_id'    => $this->product->id,
                    'has_stock_control' => $validated['has_stock_control'],
                ]);
            }

            // Atualiza tributos do produto se houver dados de tax
            if (isset($data['tax']) && is_array($data['tax'])) {

                Log::debug('Atualizando tributos do produto', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'product_id' => $this->product->id,
                    'tax_data' => $data['tax'],
                ]);

                $productTaxService = app(ProductTaxService::class);
                $productTax = $productTaxService->update($this->product->id, $this->updatedBy, $data['tax']);

                if ($productTaxService->hasError() || !$productTax) {
                    Log::warning($productTaxService->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'product_id'        => $this->product->id,
                        'service_message'   => $productTaxService->getMessage(),
                        'error_code'        => $productTaxService->getErrorCode(),
                        'errors'            => $productTaxService->getErrors(),
                    ]);
                }
            }

            $this->setSuccess();
            return $this->product;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'errors'     => $e->errors(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar produto no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;
        }
    }

    private function syncAlternativeUnitConversions(array $conversions): void
    {
        $this->product->alternativeUnitConversions()->delete();

        if ($conversions === []) {
            return;
        }

        $payload = [];

        foreach ($conversions as $conversion) {
            $payload[] = [
                'unit' => $conversion['unit'],
                'conversion_factor' => $conversion['conversion_factor'],
            ];
        }

        $this->product->alternativeUnitConversions()->createMany($payload);
    }
}
