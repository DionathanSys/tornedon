<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\Product\Origin;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Services\FiscalDocument\Validators\Items\FiscalDocumentItemValidatorResolver;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
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
            $validated = $this->normalizeForPersistence($validated);
            $validated = $this->assignItemNumberIfMissing($validated);
            $validated['created_by'] = $this->createdBy;

            $item = FiscalDocumentItem::create($validated);

            Log::info('Item de documento fiscal criado com sucesso', [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id'   => $item->id,
                'fiscal_document_id'        => $item->fiscal_document_id,
            ]);

            $this->setSuccess();
            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do item', $e->errors());

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
            $this->setError('Erro ao salvar item do documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
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
            $tableColumns = array_flip(Schema::getColumnListing((new FiscalDocumentItem())->getTable()));
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
}
