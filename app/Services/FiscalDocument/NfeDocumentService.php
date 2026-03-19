<?php

namespace App\Services\FiscalDocument;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\ConsultNfeAction;
use App\Services\FiscalDocument\Actions\PrintNfeDanfeAction;
use App\Services\FiscalDocument\Actions\PrintNfePreviewAction;
use App\Services\FiscalDocument\Actions\SaveFiscalDocumentErrorAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de orquestração da integração NF-e com a API IntegraNotas.
 *
 * O envio é sempre assíncrono: este service despacha SendNfeJob.
 * A consulta, DANFE e preview são síncronos (chamados via Filament ou HTTP).
 */
class NfeDocumentService
{
    use HandlesServiceResponse;

    public function canDelete(FiscalDocument $doc): bool
    {
        $this->resetResponse();

        if ($doc->isNfse()) {
            $this->setError('O documento informado é NFS-e. Use o fluxo de validação da NFS-e.');
            return false;
        }

        if ($doc->isInProcessing()) {
            $this->setError('Não é possível excluir uma NF-e em processamento. Aguarde a conclusão da SEFAZ.');
            return false;
        }

        if ($doc->isAuthorized()) {
            $this->setError('Não é possível excluir uma NF-e autorizada. Cancele a NF-e antes da exclusão.');
            return false;
        }

        $this->setSuccess('Documento apto para exclusão.');

        return true;
    }

    /**
     * Enfileira o envio da NF-e (assíncrono via job).
     *
     * @param FiscalDocument $doc
     * @param int            $userId
     * @param string|null    $serie           Sobrescreve a série configurada da empresa
     * @param string|null    $operationNature Sobrescreve a natureza de operação do documento
     */
    public function emitir(FiscalDocument $doc, int $userId, ?string $serie = null, ?string $operationNature = null): bool
    {
        $this->resetResponse();

        try {
            if ($doc->nfeSent() && ! $doc->isRejected()) {
                $this->setError('NF-e já enviada. Status atual: ' . $doc->nfe_status?->description());
                $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                    'contexto' => [
                        'status_atual' => $doc->nfe_status?->value,
                    ],
                ]);
                return false;
            }

            dispatch(new \App\Jobs\SendNfeJob($doc->id, $userId, $serie, $operationNature));

            $this->setSuccess('NF-e enfileirada para emissão.');

            Log::info('NfeDocumentService: job de emissão despachado', [
                'fiscal_document_id' => $doc->id,
                'user_id'            => $userId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao enfileirar emissão da NF-e: ' . $e->getMessage());

            $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                'contexto' => [
                    'exception'       => $e->getMessage(),
                    'serie'           => $serie,
                    'operationNature' => $operationNature,
                    'user_id'         => $userId,
                ],
            ]);

            Log::error('NfeDocumentService::emitir', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Consulta o status atual da NF-e na SEFAZ (síncrono).
     */
    public function consultar(FiscalDocument $doc, int $userId): bool
    {
        $this->resetResponse();

        try {
            $action = new ConsultNfeAction();
            $result = $action->execute($doc);

            if (! $result || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'consultar', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                return false;
            }

            $this->setSuccess($action->getMessage() ?: 'Consulta realizada.');
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao consultar NF-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'consultar', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::consultar', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retorna o PDF/DANFE em base64 (sob demanda, não persiste).
     */
    public function danfe(FiscalDocument $doc, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = new PrintNfeDanfeAction();
            $pdf    = $action->execute($doc);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'danfe', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('DANFE gerado.');
            return $pdf;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar DANFE: ' . $e->getMessage());
            $this->persistActionError($doc, 'danfe', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::danfe', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera preview da NF-e (não envia à SEFAZ). Retorna ['pdf'=>base64, 'xml'=>base64].
     */
    public function preview(FiscalDocument $doc, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $action = new PrintNfePreviewAction();
            $result = $action->execute($doc);

            if ($result === null || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Preview gerado.');
            return $result;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da NF-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::preview', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function persistActionError(FiscalDocument $doc, string $action, ?string $message, array $data = []): void
    {
        $persistAction = new SaveFiscalDocumentErrorAction();
        $persistAction->execute($doc, $message, array_merge($data, [
            'acao' => $action,
        ]));
    }
}
