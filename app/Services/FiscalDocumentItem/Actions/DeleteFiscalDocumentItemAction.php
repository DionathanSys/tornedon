<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteFiscalDocumentItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private FiscalDocumentItem $fiscalDocumentItem,
    ) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de item de documento fiscal', [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'fiscal_document_id'      => $this->fiscalDocumentItem->fiscal_document_id,
            ]);

            $result = $this->fiscalDocumentItem->delete();

            Log::info('Item de documento fiscal excluído com sucesso', [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'fiscal_document_id'      => $this->fiscalDocumentItem->fiscal_document_id,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir item do documento fiscal. Ele pode estar vinculado a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'message'                 => $this->getMessage(),
                'error_code'              => $this->getErrorCode(),
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'error_message'           => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'                  => __METHOD__ . '@' . __LINE__,
                'message'                 => $this->getMessage(),
                'error_code'              => $this->getErrorCode(),
                'fiscal_document_item_id' => $this->fiscalDocumentItem->id,
                'error_message'           => $e->getMessage(),
                'trace'                   => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
