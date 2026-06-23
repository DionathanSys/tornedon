<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\Product\Origin;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Services\FiscalDocument\Validators\Items\FiscalDocumentItemValidatorResolver;
use App\Services\Product\ProductUnitConversionService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateFiscalDocumentItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?FiscalDocumentItem
    {
        try {
            $data = $this->expandDotNotationPayload($data);
            $validated = FiscalDocumentItemValidatorResolver::validateCreate($data);

            $documentType = FiscalDocument::query()
                ->whereKey($validated['fiscal_document_id'] ?? null)
                ->value('document_type');

            if (
                $documentType === DocumentModel::NFSE->value
                && FiscalDocumentItem::query()
                    ->where('fiscal_document_id', (int) $validated['fiscal_document_id'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'fiscal_document_id' => 'A NFS-e permite apenas um item de serviço por documento.',
                ]);
            }

            if (
                $documentType !== DocumentModel::NFSE->value
                && (! isset($validated['product_origin']) || $validated['product_origin'] === null || $validated['product_origin'] === '')
            ) {
                $validated['product_origin'] = Origin::NACIONAL->value;
            }

            $validated = $this->ensureProductCode($validated);
            $validated = $this->applyTaxableConversion($validated);
            $validated = $this->normalizeManualTaxData($validated);
            $validated = $this->normalizeForPersistence($validated);
            $validated = $this->assignItemNumberIfMissing($validated);
            $validated['created_by'] = $this->createdBy;

            $item = FiscalDocumentItem::create($validated);

            Log::info('Item de documento fiscal criado com sucesso', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_item_id' => $item->id,
                'fiscal_document_id' => $item->fiscal_document_id,
            ]);

            $this->setSuccess();

            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do item', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'data' => $data,
                'user_id' => $this->createdBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao salvar item do documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'data' => $data,
                'user_id' => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $this->createdBy,
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
            $tableColumns = array_flip(Schema::getColumnListing((new FiscalDocumentItem)->getTable()));
        }

        $persistable = [];
        $snapshot = is_array($data['fiscal_snapshot'] ?? null) ? $data['fiscal_snapshot'] : [];

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

    private function expandDotNotationPayload(array $data): array
    {
        $expanded = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                Arr::set($expanded, $key, $value);

                continue;
            }

            $expanded[$key] = $value;
        }

        return array_replace_recursive($expanded, array_filter($data, fn ($key): bool => ! is_string($key) || ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY));
    }

    private function normalizeManualTaxData(array $data): array
    {
        if (! is_array($data['tax_data'] ?? null)) {
            return $data;
        }

        $status = (string) data_get($data, 'tax_data.imposto.icms.situacao_tributaria', '');

        if ($status === '') {
            return $data;
        }

        $hasOwnIcmsHighlight = (float) data_get($data, 'tax_data.imposto.icms.valor_base_calculo', 0) > 0
            || (float) data_get($data, 'tax_data.imposto.icms.aliquota', 0) > 0
            || (float) data_get($data, 'tax_data.imposto.icms.valor', 0) > 0;

        if (in_array($status, ['102', '103', '300', '400'], true) && $hasOwnIcmsHighlight) {
            data_set($data, 'tax_data.imposto.icms.situacao_tributaria', '900');
        }

        return $data;
    }

    private function assignItemNumberIfMissing(array $data): array
    {
        if (! empty($data['item_number']) || empty($data['fiscal_document_id'])) {
            return $data;
        }

        $lastItemNumber = FiscalDocumentItem::query()
            ->where('fiscal_document_id', (int) $data['fiscal_document_id'])
            ->lockForUpdate()
            ->max('item_number');

        $data['item_number'] = ((int) $lastItemNumber) + 1;

        return $data;
    }

    private function ensureProductCode(array $data): array
    {
        $productId = $data['product_id'] ?? null;

        if (filled($data['product_code'] ?? null) || empty($productId)) {
            return $data;
        }

        $data['product_code'] = Product::query()
            ->whereKey($productId)
            ->value('product_code');

        if (filled($data['product_code'] ?? null)) {
            return $data;
        }

        throw ValidationException::withMessages([
            'product_code' => 'O codigo do produto e obrigatorio.',
        ]);
    }

    private function applyTaxableConversion(array $data): array
    {
        $productId = (int) ($data['product_id'] ?? 0);

        if ($productId < 1 || empty($data['unit_of_measure']) || ! isset($data['quantity'], $data['total_price'])) {
            return $data;
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (! $product) {
            return $data;
        }

        $conversion = app(ProductUnitConversionService::class)
            ->convertToBase($product, (string) $data['unit_of_measure'], (float) $data['quantity']);

        $data['taxable_unit'] = $conversion->baseUnit;
        $data['taxable_quantity'] = round($conversion->baseQuantity, 4);
        $data['taxable_unit_price'] = $conversion->baseQuantity > 0
            ? round((float) $data['total_price'] / $conversion->baseQuantity, 4)
            : (float) ($data['unit_price'] ?? 0);

        return $data;
    }
}
