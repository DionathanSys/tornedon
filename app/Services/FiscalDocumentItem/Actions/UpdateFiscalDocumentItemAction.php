<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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

            $validated = NfeItemValidator::validateUpdate($data);

            unset($validated['fiscal_document_id']);
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
}
