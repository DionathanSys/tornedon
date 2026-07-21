<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Consulta o status atual de uma NF-e na API IntegraNotas pelo document_key.
 *
 * Atualiza o FiscalDocument com:
 *   - nfe_status
 *   - nfe_protocolo
 *   - document_number / document_series (quando a SEFAZ confirmar)
 *   - status (CONFIRMED / CANCELLED)
 *   - confirmed_at / canceled_at
 */
class ConsultNfeAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');

                return false;
            }

            $configService = app(\App\Services\Fiscal\NfeConfigService::class);
            $companyId = (int) $fiscalDocument->company_id;
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($companyId));

            $resp = app(\App\Services\Fiscal\IntegranotasRateLimiter::class)->run(
                token: $configService->resolveToken($companyId),
                bucket: 'key',
                key: (string) $fiscalDocument->document_key,
                callback: fn (): object => $sdk->consulta(['chave' => $fiscalDocument->document_key]),
            );

            Log::info('ConsultNfeAction: resposta da API', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo' => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? 'N/D',
                'sucesso' => $resp->sucesso ?? false,
                'protocolo' => $resp->protocolo ?? null,
            ]);

            // Ainda em processamento — sem alteração
            if (($resp->codigo ?? null) === 5023) {
                $this->setSuccess('NF-e ainda em processamento na SEFAZ.');

                return true;
            }

            $updates = [];

            if ($resp->sucesso ?? false) {
                // Autorizada
                $payload = is_array($fiscalDocument->nfe_payload) ? $fiscalDocument->nfe_payload : [];
                if (! empty($resp->xml)) {
                    $payload['xml_base64'] = $resp->xml;
                }
                if (! empty($resp->pdf)) {
                    $payload['pdf_base64'] = $resp->pdf;
                }

                $updates['nfe_status'] = NfeStatus::AUTHORIZED->value;
                $updates['nfe_protocolo'] = $resp->protocolo ?? null;
                $updates['status'] = Status::CONFIRMED->value;
                $updates['confirmed_at'] = now();
                $updates['nfe_payload'] = $payload;

                if (! empty($resp->numero)) {
                    $updates['document_number'] = $resp->numero;
                }
                if (! empty($resp->serie)) {
                    $updates['document_series'] = $resp->serie;
                }

                Log::info('ConsultNfeAction: NF-e autorizada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'protocolo' => $resp->protocolo ?? null,
                    'chave' => $fiscalDocument->document_key,
                ]);
            } else {
                // Rejeitada
                $updates['nfe_status'] = NfeStatus::REJECTED->value;
                $updates['status'] = Status::PENDING->value;

                $errors = $fiscalDocument->errors_messages ?? [];
                $errors[] = [
                    'at' => now()->toDateTimeString(),
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? 'Desconhecido',
                ];
                $updates['errors_messages'] = $errors;

                Log::warning('ConsultNfeAction: NF-e rejeitada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? null,
                ]);

                $this->setError($resp->mensagem ?? 'NF-e rejeitada pela API.', (array) ($resp->erros ?? []));
            }

            $fiscalDocument->update($updates);
            $fiscalDocument->refresh();

            if (($updates['nfe_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfe_authorized',
                    'NF-e autorizada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            } elseif (($updates['nfe_status'] ?? null) === NfeStatus::REJECTED->value) {
                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfe_rejected',
                    'NF-e rejeitada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::JOB,
                );
            }

            if (($updates['nfe_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                if ($fiscalDocument->fresh()->isPurchaseReturn()) {
                    $purchaseReturnAction = app(ProcessAuthorizedPurchaseReturnAction::class);
                    $purchaseReturnAction->execute(
                        $fiscalDocument->fresh(),
                        (int) ($fiscalDocument->updated_by ?? $fiscalDocument->created_by ?? 1)
                    );

                    if ($purchaseReturnAction->hasError()) {
                        Log::warning('ConsultNfeAction: falha ao processar devolucao apos autorizacao', [
                            'fiscal_document_id' => $fiscalDocument->id,
                            'message' => $purchaseReturnAction->getMessage(),
                            'errors' => $purchaseReturnAction->getErrors(),
                        ]);
                    }

                    $this->setSuccess();

                    return true;
                }

                $stockMovementAction = app(ProcessAuthorizedNfeStockMovementsAction::class);
                if (! $stockMovementAction->execute($fiscalDocument->fresh(['invoice.requisitions.items.product']))) {
                    Log::warning('ConsultNfeAction: falha ao processar movimentações de estoque após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'message' => $stockMovementAction->getMessage(),
                        'errors' => $stockMovementAction->getErrors(),
                    ]);
                }

                $storeAttachmentsAction = app(StoreFiscalDocumentAttachmentsAction::class);
                if (! $storeAttachmentsAction->execute($fiscalDocument->fresh())) {
                    Log::warning('ConsultNfeAction: falha ao persistir anexos fiscais após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'message' => $storeAttachmentsAction->getMessage(),
                        'errors' => $storeAttachmentsAction->getErrors(),
                    ]);
                }

                $generationService = app(AccountReceivableGenerationService::class);
                $ok = $generationService->generateFromFiscalDocument($fiscalDocument->fresh(['invoice']));

                if (! $ok) {
                    Log::warning('ConsultNfeAction: falha ao gerar contas a receber após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'invoice_id' => $fiscalDocument->invoice_id,
                        'message' => $generationService->getMessage(),
                        'error_code' => $generationService->getErrorCode(),
                        'errors' => $generationService->getErrors(),
                    ]);
                }

            }

            if ($this->hasError()) {
                return false;
            }

            $this->setSuccess();

            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao consultar NF-e: '.$e->getMessage());

            Log::error('ConsultNfeAction: exceção', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
