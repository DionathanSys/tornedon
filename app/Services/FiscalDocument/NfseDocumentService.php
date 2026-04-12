<?php

namespace App\Services\FiscalDocument;

use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Actions\CancelNfseAction;
use App\Services\FiscalDocument\Actions\ConsultNfseAction;
use App\Services\FiscalDocument\Actions\PrintNfsePdfAction;
use App\Services\FiscalDocument\Actions\PrintNfsePreviewAction;
use App\Services\FiscalDocument\Actions\ReserveRpsNumberAction;
use App\Services\FiscalDocument\Actions\SaveFiscalDocumentErrorAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de orquestração da integração NFS-e com a API IntegraNotas.
 *
 * O envio é sempre assíncrono: este service despacha SendNfseJob.
 * A consulta, PDF e cancelamento são síncronos (chamados via Filament ou HTTP).
 */
class NfseDocumentService
{
    use HandlesServiceResponse;

    public function canDelete(FiscalDocument $doc): bool
    {
        $this->resetResponse();

        if (! $doc->isNfse()) {
            $this->setError('O documento informado não é NFS-e. Use o fluxo de validação da NF-e.');
            return false;
        }

        if ($doc->isNfseInProcessing()) {
            $this->setError('Não é possível excluir uma NFS-e em processamento. Aguarde o retorno da prefeitura.');
            return false;
        }

        if ($doc->isNfseAuthorized()) {
            $this->setError('Não é possível excluir uma NFS-e autorizada. Cancele a NFS-e antes da exclusão.');
            return false;
        }

        $this->setSuccess('Documento apto para exclusão.');

        return true;
    }

    /**
     * Enfileira o envio da NFS-e (assíncrono via job).
     */
    public function emitir(FiscalDocument $doc, int $userId, ?string $serie = null): bool
    {
        $this->resetResponse();

        try {
            if ($doc->isNfseSent() && ! $doc->isNfseRejected()) {
                $this->setError('NFS-e já enviada. Status atual: ' . $doc->nfse_status?->description());
                $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                    'contexto' => [
                        'status_atual' => $doc->nfse_status?->value,
                    ],
                ]);
                return false;
            }

            $currentNumber = (int) preg_replace('/\D/', '', (string) ($doc->rps_number ?? ''));

            if ($currentNumber < 1) {
                $configService = app(NfseConfigService::class);
                $serie = $serie ?? $configService->resolveSerie($doc->company_id);

                $reserveAction = new ReserveRpsNumberAction();

                if (! $reserveAction->execute($doc, $serie)) {
                    $this->setError($reserveAction->getMessage());
                    $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                        'contexto' => [
                            'serie'   => $serie,
                            'user_id' => $userId,
                        ],
                    ]);
                    return false;
                }

                $doc->refresh();
            }

            dispatch(new \App\Jobs\SendNfseJob($doc->id, $userId, $serie));

            $this->setSuccess('NFS-e enfileirada para emissão.');

            Log::info('NfseDocumentService: job de emissão despachado', [
                'fiscal_document_id' => $doc->id,
                'user_id'            => $userId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao enfileirar emissão da NFS-e: ' . $e->getMessage());

            $this->persistActionError($doc, 'emitir', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                    'serie'     => $serie,
                    'user_id'   => $userId,
                ],
            ]);

            Log::error('NfseDocumentService::emitir', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Consulta o status atual da NFS-e (síncrono).
     */
    public function consultar(FiscalDocument $doc, int $userId): bool
    {
        $this->resetResponse();

        try {
            $action = new ConsultNfseAction();
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
            $this->setError('Erro ao consultar NFS-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'consultar', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfseDocumentService::consultar', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retorna o PDF da NFS-e em base64 (sob demanda, não persiste).
     */
    public function pdf(FiscalDocument $doc, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = new PrintNfsePdfAction();
            $pdf    = $action->execute($doc);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'pdf', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('PDF gerado.');
            return $pdf;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da NFS-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'pdf', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfseDocumentService::pdf', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera preview da NFS-e (não envia à API). Retorna ['pdf'=>base64, 'xml'=>base64].
     */
    public function preview(FiscalDocument $doc, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $action = new PrintNfsePreviewAction();
            $data   = $action->execute($doc);

            if (! $data || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Preview gerado.');
            return $data;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da NFS-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'preview', $this->getMessageUser(), [
                'contexto' => [
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfseDocumentService::preview', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cancela uma NFS-e autorizada (síncrono).
     */
    public function cancelar(
        FiscalDocument $doc,
        string $codigoCancelamento = '9',
        string $motivoCancelamento = 'Cancelamento solicitado',
        ?int $userId = null
    ): bool
    {
        $this->resetResponse();

        try {
            $action = new CancelNfseAction();
            $result = $action->execute($doc, $codigoCancelamento, $motivoCancelamento);

            if (! $result || $action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                $this->persistActionError($doc, 'cancelar', $this->getMessageUser(), [
                    'erros' => $action->getErrors(),
                    'contexto' => [
                        'codigo_cancelamento' => $codigoCancelamento,
                        'motivo_cancelamento' => $motivoCancelamento,
                        'user_id' => $userId,
                    ],
                ]);
                return false;
            }

            $this->setSuccess('NFS-e cancelada com sucesso.');
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar NFS-e: ' . $e->getMessage());
            $this->persistActionError($doc, 'cancelar', $this->getMessageUser(), [
                'contexto' => [
                    'codigo_cancelamento' => $codigoCancelamento,
                    'motivo_cancelamento' => $motivoCancelamento,
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ],
            ]);

            Log::error('NfseDocumentService::cancelar', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $doc->id,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function getDefaultServiceCode(int $companyId): string
    {
        return FiscalProfile::query()->where('company_id', $companyId)->value('default_service_code');
    }

    public static function getDefaultMunicipalTaxCode(int $companyId): string
    {
        return FiscalProfile::query()->where('company_id', $companyId)->value('default_municipal_tax_code');
    }

    public static function getDefaultNbsCode(int $companyId): string
    {
        return FiscalProfile::query()->where('company_id', $companyId)->value('default_nbs_code');
    }

    public static function getDefaultCnaeCode(int $companyId): string
    {
        $cnaeCode = FiscalProfile::query()->where('company_id', $companyId)->value('service_cnae_code');
        return str_replace(['.', '-'], '', $cnaeCode ?? '');
    }

    private function persistActionError(FiscalDocument $doc, string $action, ?string $message, array $data = []): void
    {
        $persistAction = new SaveFiscalDocumentErrorAction();
        $persistAction->execute($doc, $message, array_merge($data, [
            'acao' => $action,
        ]));
    }
}
