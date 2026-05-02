<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Services\FiscalDocument\Validators\Items\FiscalDocumentItemValidatorResolver;
use App\Services\Product\ProductUnitConversionService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UpdateFiscalDocumentItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int                $updatedBy,
        private FiscalDocumentItem $fiscalDocumentItem,
    ) {}

    public function execute(array $data): ?FiscalDocumentItem
    {
        try {
            Log::debug('Iniciando atualização de item de documento fiscal', [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'user_id'                 => $this->updatedBy,
                'data'                    => $data,
            ]);

            $validated = FiscalDocumentItemValidatorResolver::validateUpdate(
                $data,
                $this->fiscalDocumentItem->fiscal_document_id
            );

            unset($validated['fiscal_document_id']);
            $validated = $this->ensureProductCode($validated);
            $validated = $this->applyTaxableConversion($validated);
            $validated = $this->normalizeForPersistence($validated);
            $validated['updated_by'] = $this->updatedBy;

            $this->fiscalDocumentItem->update($validated);
            $this->fiscalDocumentItem->refresh();

            Log::info('Item de documento fiscal atualizado com sucesso', [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'fiscal_document_id'      => $this->fiscalDocumentItem->fiscal_document_id,
                'user_id'                 => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->fiscalDocumentItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do item', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'message'                 => $this->getMessage(),
                'error_code'              => $this->getErrorCode(),
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'errors'                  => $e->errors(),
                'data'                    => $data,
                'user_id'                 => $this->updatedBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item do documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'message'                 => $this->getMessage(),
                'error_code'              => $this->getErrorCode(),
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'error_message'           => $e->getMessage(),
                'data'                    => $data,
                'user_id'                 => $this->updatedBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'message'                 => $this->getMessage(),
                'error_code'              => $this->getErrorCode(),
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'error_message'           => $e->getMessage(),
                'trace'                   => $e->getTraceAsString(),
                'data'                    => $data,
                'user_id'                 => $this->updatedBy,
            ]);

            return null;
        }
    }

    private function normalizeForPersistence(array $data): array
    {
        if (blank($data['municipal_tax_code'] ?? null) && filled($data['service_code'] ?? null)) {
            $data['municipal_tax_code'] = $data['service_code'];
        }

        // Garante que iss_exigibility seja sempre string
        if (isset($data['iss_exigibility']) && ! is_null($data['iss_exigibility'])) {
            $data['iss_exigibility'] = (string) $data['iss_exigibility'];
        }

        static $tableColumns = null;

        if ($tableColumns === null) {
            $tableColumns = array_flip(Schema::getColumnListing($this->fiscalDocumentItem->getTable()));
        }

        $persistable = [];
        $snapshot = is_array($this->fiscalDocumentItem->fiscal_snapshot ?? null)
            ? $this->fiscalDocumentItem->fiscal_snapshot
            : [];

        foreach ($data as $key => $value) {
            if (isset($tableColumns[$key])) {
                $persistable[$key] = $value;
                continue;
            }

            $snapshot[$key] = $value;
        }

        if (! empty($snapshot)) {
            $persistable['fiscal_snapshot'] = $snapshot;
        }

        return $persistable;
    }

    private function ensureProductCode(array $data): array
    {
        $productId = $data['product_id'] ?? $this->fiscalDocumentItem->product_id;

        if (empty($productId)) {
            return $data;
        }

        if (filled($data['product_code'] ?? null)) {
            return $data;
        }

        $data['product_code'] = Product::query()
            ->whereKey($productId)
            ->value('product_code')
            ?? $this->fiscalDocumentItem->product_code;

        if (filled($data['product_code'] ?? null)) {
            return $data;
        }

        throw ValidationException::withMessages([
            'product_code' => 'O codigo do produto e obrigatorio.',
        ]);
    }

    private function applyTaxableConversion(array $data): array
    {
        $productId = (int) ($data['product_id'] ?? $this->fiscalDocumentItem->product_id);
        $unit = $data['unit_of_measure'] ?? $this->fiscalDocumentItem->unit_of_measure;
        $quantity = (float) ($data['quantity'] ?? $this->fiscalDocumentItem->quantity ?? 0);
        $totalPrice = (float) ($data['total_price'] ?? $this->fiscalDocumentItem->total_price ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? $this->fiscalDocumentItem->unit_price ?? 0);

        if ($productId < 1 || empty($unit) || $quantity <= 0) {
            return $data;
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (! $product) {
            return $data;
        }

        $conversion = app(ProductUnitConversionService::class)
            ->convertToBase($product, (string) $unit, $quantity);

        $data['taxable_unit'] = $conversion->baseUnit;
        $data['taxable_quantity'] = round($conversion->baseQuantity, 4);
        $data['taxable_unit_price'] = $conversion->baseQuantity > 0
            ? round($totalPrice / $conversion->baseQuantity, 4)
            : $unitPrice;

        return $data;
    }
}
