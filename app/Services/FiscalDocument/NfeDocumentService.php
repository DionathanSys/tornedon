<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\NfeStatus;
use App\Jobs\ProcessQueuedNfeEmissionJob;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\CancelNfeAction;
use App\Services\FiscalDocument\Actions\ConsultNfeAction;
use App\Services\FiscalDocument\Actions\CorrectNfeAction;
use App\Services\FiscalDocument\Actions\PrintNfeDanfeAction;
use App\Services\FiscalDocument\Actions\PrintNfePreviewAction;
use App\Services\FiscalDocument\Actions\SaveFiscalDocumentErrorAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de orquestração da integração NF-e com a API IntegraNotas.
 *
 * A solicitação de emissão é enfileirada e processada em série por grupo de emissão.
 * A consulta de retorno permanece assíncrona via polling/webhook.
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

        if ($doc->isNfeInProcessing()) {
            $this->setError('Não é possível excluir uma NF-e em processamento. Aguarde a conclusão da SEFAZ.');

            return false;
        }

        if ($doc->isNfeAuthorized()) {
            $this->setError('Não é possível excluir uma NF-e autorizada. Cancele a NF-e antes da exclusão.');

            return false;
        }

        $this->setSuccess('Documento apto para exclusão.');

        return true;
    }

    /**
     * Valida e enfileira a emissão da NF-e.
     *
     * @param  string|null  $serie  Sobrescreve a série configurada da empresa
     * @param  string|null  $operationNature  Sobrescreve a natureza de operação do documento
     */
    public function emitir(FiscalDocument $doc, int $userId, ?string $serie = null, ?string $operationNature = null): bool
    {
        $this->resetResponse();

        try {
            if ($doc->blocksNfeResubmission()) {
                $this->setError('NF-e já possui solicitação de emissão. Status atual: '.$doc->nfe_status?->description());
                $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                    'contexto' => [
                        'status_atual' => $doc->nfe_status?->value,
                    ],
                ]);

                return false;
            }

            $preflightService = app(FiscalEmissionPreflightService::class);
            $preflight = $preflightService->validateForQueue($doc);

            if ($preflight === null || $preflightService->hasError()) {
                $this->setError($preflightService->getMessage(), $preflightService->getErrors());
                $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                    'erros' => $preflightService->getErrors(),
                    'contexto' => [
                        'serie' => $serie,
                        'operationNature' => $operationNature,
                        'user_id' => $userId,
                        'scenario_code' => $preflight?->scenarioCode,
                    ],
                ]);

                return false;
            }

            $doc->update([
                'status' => \App\Enum\FiscalDocument\Status::PENDING->value,
                'nfe_status' => NfeStatus::QUEUED->value,
                'emission_requested_at' => now(),
                'emission_group_key' => $preflight->queueGroupKey,
                'updated_by' => $userId,
            ]);

            dispatch(new ProcessQueuedNfeEmissionJob($preflight->queueGroupKey));

            $this->setSuccess('NF-e enfileirada para emissão.');

            Log::info('NfeDocumentService: emissão enfileirada', [
                'fiscal_document_id' => $doc->id,
                'emission_group_key' => $preflight->queueGroupKey,
                'user_id' => $userId,
                'scenario_code' => $preflight->scenarioCode,
                'channel_code' => $preflight->channelCode,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao enfileirar emissão da NF-e: '.$e->getMessage());

            $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                    'serie' => $serie,
                    'operationNature' => $operationNature,
                    'user_id' => $userId,
                ],
            ]);

            Log::error('NfeDocumentService::emitir', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
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
            $action = new ConsultNfeAction;
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
            $this->setError('Erro ao consultar NF-e: '.$e->getMessage());
            $this->persistActionError($doc, 'consultar', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::consultar', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
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
            $action = new PrintNfeDanfeAction;
            $pdf = $action->execute($doc);

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
            $this->setError('Erro ao gerar DANFE: '.$e->getMessage());
            $this->persistActionError($doc, 'danfe', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::danfe', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
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
            $preflightService = app(\App\Services\FiscalDocument\FiscalEmissionPreflightService::class);
            $preflight = $preflightService->validateForSend($doc);

            if ($preflight === null || $preflightService->hasError()) {
                $this->setError($preflightService->getMessage(), $preflightService->getErrors());
                $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                    'erros' => $preflightService->getErrors(),
                ]);
                Log::warning('NfeDocumentService::preview - preflight invalido', [
                    'fiscal_document_id' => $doc->id,
                    'erros' => $preflightService->getErrors(),
                ]);

                return null;
            }

            $action = new PrintNfePreviewAction;
            $result = $action->execute($doc);

            if ($result === null || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                Log::error('NfeDocumentService::preview - falha ao gerar preview', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'fiscal_document_id' => $doc->id,
                    'message' => $this->getMessageUser(),
                    'erros' => $action->getErrors(),
                ]);

                return null;
            }

            $this->setSuccess('Preview gerado.');

            return $result;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da NF-e: '.$e->getMessage());
            $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::preview', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancela uma NF-e autorizada (sincrono).
     */
    public function cancelar(
        FiscalDocument $doc,
        string $justificativa,
        ?int $userId = null
    ): bool {
        $this->resetResponse();

        try {
            $action = new CancelNfeAction;
            $result = $action->execute($doc, $justificativa);

            if (! $result || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'cancelar', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                    'contexto' => [
                        'justificativa' => $justificativa,
                        'user_id' => $userId,
                    ],
                ]);

                return false;
            }

            $this->setSuccess('NF-e cancelada com sucesso.');

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar NF-e: '.$e->getMessage());
            $this->persistActionError($doc, 'cancelar', $this->getMessageUser(), [
                'contexto' => [
                    'justificativa' => $justificativa,
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::cancelar', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Emite uma carta de correção para uma NF-e autorizada (síncrono).
     */
    public function corrigir(
        FiscalDocument $doc,
        string $justificativa,
        ?int $sequencial = null,
        ?int $userId = null
    ): bool {
        $this->resetResponse();

        try {
            $action = new CorrectNfeAction;
            $result = $action->execute($doc, $justificativa, $sequencial);

            if (! $result || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'corrigir', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                    'contexto' => [
                        'justificativa' => $justificativa,
                        'sequencial' => $sequencial,
                        'user_id' => $userId,
                    ],
                ]);

                return false;
            }

            $this->setSuccess('Carta de correção emitida com sucesso.');

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao emitir carta de correção da NF-e: '.$e->getMessage());
            $this->persistActionError($doc, 'corrigir', $this->getMessageUser(), [
                'contexto' => [
                    'justificativa' => $justificativa,
                    'sequencial' => $sequencial,
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfeDocumentService::corrigir', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $doc->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function persistActionError(FiscalDocument $doc, string $action, ?string $message, array $data = []): void
    {
        $persistAction = new SaveFiscalDocumentErrorAction;
        $persistAction->execute($doc, $message, array_merge($data, [
            'acao' => $action,
        ]));
    }
}
