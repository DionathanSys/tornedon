<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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

            $validated = NfeItemValidator::validateCreateMany(['items' => $items]);

            Log::debug('Validação dos itens de documento fiscal concluída', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'validated'  => $validated,
                'user_id'    => $this->createdBy,
            ]);

            $now = now();
            $records = array_map(fn (array $item) => array_merge($item, [
                'created_by'  => $this->createdBy,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]), $validated['items']);

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
}
