<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\Product\Origin;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Services\FiscalDocument\Validators\Items\FiscalDocumentItemValidatorResolver;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateManyFiscalDocumentItemsAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria múltiplos itens de documento fiscal em uma única operação.
     *
     * @param  array  $items  Array de itens (sem prefixo 'items')
     * @return FiscalDocumentItem[]|null
     */
    public function execute(array $items): ?array
    {
        try {
            Log::debug('Iniciando criação em lote de itens de documento fiscal', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'items'      => $items,
                'user_id'    => $this->createdBy,
            ]);

            $validated = FiscalDocumentItemValidatorResolver::validateCreateMany(['items' => $items]);

            $fiscalDocumentId = $validated['items'][0]['fiscal_document_id'] ?? null;
            $documentType = FiscalDocument::query()
                ->whereKey($fiscalDocumentId)
                ->value('document_type');

            Log::debug('Validação dos itens de documento fiscal concluída', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'validated'  => $validated,
                'document_type' => $documentType,
                'user_id'    => $this->createdBy,
            ]);

            $validatedItems = $this->assignMissingItemNumbers($validated['items']);

            $now = now();
            $records = array_map(function (array $item) use ($now, $documentType): array {
                if (
                    $documentType !== DocumentModel::NFSE->value
                    && (! isset($item['product_origin']) || $item['product_origin'] === null || $item['product_origin'] === '')
                ) {
                    $item['product_origin'] = Origin::NACIONAL->value;
                }

                $item = $this->normalizeForPersistence($item);

                // O insert() em lote ignora casts do Eloquent.
                // Fazemos fill() para aplicar casts (ex.: MoneyCast) antes de inserir.
                $model = new FiscalDocumentItem();
                $model->fill($item);

                return array_merge($model->getAttributes(), [
                    'created_by'  => $this->createdBy,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }, $validatedItems);

            Log::debug('Preparação dos registros para inserção em lote', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'records'    => $records,
                'user_id'    => $this->createdBy,
            ]);

            FiscalDocumentItem::insert($records);

            Log::info('Itens de documento fiscal criados em lote', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $records[0]['fiscal_document_id'] ?? null,
                'total_items'        => count($records),
            ]);

            $this->setSuccess();

            // Retorna os itens recém-criados
            $fiscalDocumentId = $records[0]['fiscal_document_id'] ?? null;
            return FiscalDocumentItem::where('fiscal_document_id', $fiscalDocumentId)
                ->where('created_by', $this->createdBy)
                ->where('created_at', $now)
                ->get()
                ->all();
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos itens do documento fiscal', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar itens do documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'user_id'       => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar itens do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'user_id'       => $this->createdBy,
            ]);

            return null;
        }
    }

    private function normalizeForPersistence(array $data): array
    {
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

    private function assignMissingItemNumbers(array $items): array
    {
        $fiscalDocumentId = $items[0]['fiscal_document_id'] ?? null;

        if (! $fiscalDocumentId) {
            return $items;
        }

        $nextItemNumber = ((int) FiscalDocumentItem::query()
            ->where('fiscal_document_id', (int) $fiscalDocumentId)
            ->lockForUpdate()
            ->max('item_number')) + 1;

        foreach ($items as $index => $item) {
            if (! empty($item['item_number'])) {
                continue;
            }

            $items[$index]['item_number'] = $nextItemNumber;
            $nextItemNumber++;
        }

        return $items;
    }
}
