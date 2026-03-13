<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteFiscalDocumentAction
{
    use HandlesActionResponse;

    public function __construct(
        private FiscalDocument $fiscalDocument,
    ) {}

    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão de documento fiscal', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $this->fiscalDocument->id,
            ]);

            if (!$this->validateCanDelete()) {
                return false;
            }

            $result = $this->fiscalDocument->delete();

            Log::info('Documento fiscal excluído com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $this->fiscalDocument->id,
            ]);

            $this->setSuccess();
            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir documento fiscal. Ele pode estar vinculado a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
                'error_message'      => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
                'error_message'      => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        if ($this->fiscalDocument->confirmed) {
            $this->setError('Não é possível excluir um documento fiscal confirmado');

            Log::warning($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
            ]);

            return false;
        }

        if ($this->fiscalDocument->isNfse()) {
            if ($this->fiscalDocument->isNfseInProcessing()) {
                $this->setError('Não é possível excluir uma NFS-e em processamento. Aguarde o retorno da prefeitura.');

                Log::warning($this->getMessage(), [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'message'            => $this->getMessage(),
                    'error_code'         => $this->getErrorCode(),
                    'fiscal_document_id' => $this->fiscalDocument->id,
                ]);

                return false;
            }

            if ($this->fiscalDocument->isNfseAuthorized()) {
                $this->setError('Não é possível excluir uma NFS-e autorizada. Cancele a NFS-e antes da exclusão.');

                Log::warning($this->getMessage(), [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'message'            => $this->getMessage(),
                    'error_code'         => $this->getErrorCode(),
                    'fiscal_document_id' => $this->fiscalDocument->id,
                ]);

                return false;
            }
        } else {
            if ($this->fiscalDocument->isInProcessing()) {
                $this->setError('Não é possível excluir uma NF-e em processamento. Aguarde o retorno da SEFAZ.');

                Log::warning($this->getMessage(), [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'message'            => $this->getMessage(),
                    'error_code'         => $this->getErrorCode(),
                    'fiscal_document_id' => $this->fiscalDocument->id,
                ]);

                return false;
            }

            if ($this->fiscalDocument->isAuthorized()) {
                $this->setError('Não é possível excluir uma NF-e autorizada. Cancele a NF-e antes da exclusão.');

                Log::warning($this->getMessage(), [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'message'            => $this->getMessage(),
                    'error_code'         => $this->getErrorCode(),
                    'fiscal_document_id' => $this->fiscalDocument->id,
                ]);

                return false;
            }
        }

        if ($this->fiscalDocument->accountPayables()->exists()) {
            $this->setError('Não é possível excluir documento fiscal que possui contas a pagar vinculadas');

            Log::warning($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'message'            => $this->getMessage(),
                'error_code'         => $this->getErrorCode(),
                'fiscal_document_id' => $this->fiscalDocument->id,
            ]);

            return false;
        }

        return true;
    }
}
