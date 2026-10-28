<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\IntegranotasRateLimiter;
use App\Services\Fiscal\NfseConfigService;
use App\Traits\HandlesActionResponse;
use CloudDfe\SdkPHP\Nfse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Consulta o status atual de uma NFS-e na API IntegraNotas pelo document_key.
 *
 * Atualiza o FiscalDocument com:
 *   - nfse_status
 *   - nfse_protocol
 *   - status (CONFIRMED / CANCELLED)
 *   - authorized_at / canceled_at
 */
class ConsultNfseAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        $this->resetResponse();

        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);

            Log::debug('ConsultNfseAction: consultando status de NFS-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'document_key' => $fiscalDocument->document_key,
                'status_atual' => $fiscalDocument->nfse_status?->value,
            ]);

            if (empty($fiscalDocument->document_key)) {
                $msgErro = 'Chave de acesso não encontrada no documento fiscal.';
                $this->setError($msgErro);
                Log::warning('ConsultNfseAction: chave de acesso ausente', [
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return false;
            }

            $configService = app(NfseConfigService::class);
            $companyId = (int) $fiscalDocument->company_id;
            $sdk = new Nfse($configService->buildSdkParams($companyId));

            $resp = app(IntegranotasRateLimiter::class)->run(
                token: $configService->resolveToken($companyId),
                bucket: 'key',
                key: (string) $fiscalDocument->document_key,
                callback: fn (): object => $sdk->consulta(['chave' => $fiscalDocument->document_key]),
            );

            Log::info('ConsultNfseAction: resposta da API recebida', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo' => $resp->codigo ?? null,
                'sucesso' => $resp->sucesso ?? false,
                'mensagem' => $resp->mensagem ?? null,
                'protocolo' => $resp->protocolo ?? null,
            ]);

            // Ainda em processamento
            if (($resp->codigo ?? null) === 5023) {
                Log::info('ConsultNfseAction: NFS-e ainda em processamento', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo,
                ]);
                $this->setSuccess();

                return true;
            }

            $updates = [];
            $payloadUpdates = [];
            $responseStatus = mb_strtolower(trim((string) ($resp->status ?? $resp->situacao ?? '')));
            $isCanceled = ($resp->sucesso ?? false) && (
                (int) ($resp->codigo ?? 0) === 101
                || in_array($responseStatus, ['cancelado', 'cancelada', 'canceled', 'cancelled'], true)
            );

            if ($resp->sucesso ?? false) {
                $payload = is_array($fiscalDocument->nfse_payload) ? $fiscalDocument->nfse_payload : [];
                if (! empty($resp->xml)) {
                    $payload['xml_base64'] = $resp->xml;
                }
                if (! empty($resp->pdf)) {
                    $payload['pdf_base64'] = $resp->pdf;
                }

                $payloadUpdates['nfse_payload'] = $payload;
            }

            if ($isCanceled) {
                $updates['nfse_status'] = NfeStatus::CANCELED->value;
                $updates['status'] = Status::CANCELLED->value;
                $updates['canceled_at'] = ! empty($resp->data_hora_evento)
                    ? Carbon::parse($resp->data_hora_evento)
                    : ($fiscalDocument->canceled_at ?? now());

                Log::info('ConsultNfseAction: NFS-e cancelada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave' => $fiscalDocument->document_key,
                    'codigo' => $resp->codigo ?? null,
                ]);
            } elseif ($resp->sucesso ?? false) {
                // Autorizada
                $updates['nfse_status'] = NfeStatus::AUTHORIZED->value;
                $updates['nfse_protocol'] = $resp->protocolo ?? null;
                $updates['status'] = Status::CONFIRMED->value;
                $updates['authorized_at'] = now();

                if (! empty($resp->numero)) {
                    $updates['document_number'] = $resp->numero;
                }
                if (! empty($resp->serie)) {
                    $updates['document_series'] = $resp->serie;
                }

                Log::info('ConsultNfseAction: NFS-e autorizada com sucesso', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'protocolo' => $resp->protocolo ?? null,
                    'chave' => $fiscalDocument->document_key,
                    'numero' => $resp->numero ?? null,
                    'serie' => $resp->serie ?? null,
                ]);
            } else {
                // Rejeitada
                $canReuseNumber = NfseSequence::canReuseNumber($fiscalDocument);

                $updates['nfse_status'] = $canReuseNumber
                    ? NfeStatus::REJECTED->value
                    : NfeStatus::RPS_RECONCILIATION_PENDING->value;
                $updates['status'] = Status::PENDING->value;

                $errors = $fiscalDocument->errors_messages ?? [];
                $baseMessage = $resp->mensagem ?? 'Desconhecido';

                if (! empty($resp->erros) && is_array($resp->erros)) {
                    foreach ($resp->erros as $erroItem) {
                        $erroData = is_object($erroItem) ? (array) $erroItem : $erroItem;

                        $campo = $erroData['campo'] ?? 'N/A';
                        $erroMsg = $erroData['erro'] ?? 'N/A';
                        $descricao = $erroData['descricao'] ?? 'N/A';
                        $detalhe = $erroData['detalhes'] ?? 'N/A';

                        $formattedMessage = "{$baseMessage}\nCampo: {$campo}\nErro: {$erroMsg}\nDescrição: {$descricao}\nDetalhe: {$detalhe}";

                        $errors[] = [
                            'at' => now()->toDateTimeString(),
                            'codigo' => $resp->codigo ?? null,
                            'mensagem' => $formattedMessage,
                            'erros' => $erroData,
                        ];
                    }
                } else {
                    $errors[] = [
                        'at' => now()->toDateTimeString(),
                        'codigo' => $resp->codigo ?? null,
                        'mensagem' => $baseMessage,
                    ];
                }

                if (! $canReuseNumber) {
                    $errors[] = [
                        'at' => now()->toDateTimeString(),
                        'codigo' => 'rps_reconciliation',
                        'mensagem' => 'NFS-e rejeitada após aceite, mas o RPS não é mais o maior da série. Conciliação necessária antes de novo envio.',
                    ];
                }

                $updates['errors_messages'] = $errors;

                Log::warning('ConsultNfseAction: NFS-e rejeitada pela API', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? null,
                ]);

                $this->setError($resp->mensagem ?? 'NFS-e rejeitada pela API.', (array) ($resp->erros ?? []));
            }

            $fiscalDocument->update($updates);
            if ($payloadUpdates !== []) {
                app(UpsertFiscalDocumentPayloadAction::class)->execute($fiscalDocument, $payloadUpdates);
            }
            $fiscalDocument->refresh();

            if (($updates['nfse_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_authorized',
                    'NFS-e autorizada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            } elseif (($updates['nfse_status'] ?? null) === NfeStatus::CANCELED->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_canceled',
                    'NFS-e cancelada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            } elseif (($updates['nfse_status'] ?? null) === NfeStatus::REJECTED->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_rejected',
                    'NFS-e rejeitada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            } elseif (($updates['nfse_status'] ?? null) === NfeStatus::RPS_RECONCILIATION_PENDING->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_rps_reconciliation_pending',
                    'NFS-e pendente de conciliação de RPS',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            }

            if (($updates['nfse_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                $storeAttachmentsAction = app(StoreFiscalDocumentAttachmentsAction::class);
                if (! $storeAttachmentsAction->execute($fiscalDocument->fresh())) {
                    Log::warning('ConsultNfseAction: falha ao persistir anexos fiscais após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'message' => $storeAttachmentsAction->getMessage(),
                        'errors' => $storeAttachmentsAction->getErrors(),
                    ]);
                }

                Log::debug('ConsultNfseAction: iniciando geração de contas a receber', [
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                $generationService = app(AccountReceivableGenerationService::class);
                $ok = $generationService->generateFromFiscalDocument($fiscalDocument->fresh(['invoice']));

                if (! $ok) {
                    Log::warning('ConsultNfseAction: falha ao gerar contas a receber após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'invoice_id' => $fiscalDocument->invoice_id,
                        'message' => $generationService->getMessage(),
                        'error_code' => $generationService->getErrorCode(),
                        'errors' => $generationService->getErrors(),
                    ]);
                } else {
                    Log::info('ConsultNfseAction: contas a receber geradas com sucesso', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'invoice_id' => $fiscalDocument->invoice_id,
                    ]);
                }

            }

            Log::info('ConsultNfseAction: execução concluída com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'status_final' => $updates['nfse_status'] ?? null,
            ]);

            if (! ($resp->sucesso ?? false)) {
                return false;
            }

            $this->setSuccess();

            return true;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao consultar NFS-e: '.$e->getMessage();
            $this->setError($msgErro);

            Log::error('ConsultNfseAction: exceção capturada', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception' => $e->getMessage(),
                'erro_classe' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
