<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Email\CustomerDocumentEmailService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Consulta o status atual de uma NFS-e na API IntegraNotas pelo document_key.
 *
 * Atualiza o FiscalDocument com:
 *   - nfse_status
 *   - nfse_protocol
 *   - status (CONFIRMED / CANCELLED)
 *   - confirmed_at / canceled_at
 */
class ConsultNfseAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        try {
            Log::debug('ConsultNfseAction: consultando status de NFS-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'document_key'       => $fiscalDocument->document_key,
                'status_atual'       => $fiscalDocument->nfse_status?->value,
            ]);

            if (empty($fiscalDocument->document_key)) {
                $msgErro = 'Chave de acesso não encontrada no documento fiscal.';
                $this->setError($msgErro);
                Log::warning('ConsultNfseAction: chave de acesso ausente', [
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);
                return false;
            }

            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->consulta(['chave' => $fiscalDocument->document_key]);

            Log::debug('ConsultNfseAction: resposta da API recebida', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo'             => $resp->codigo ?? null,
                'sucesso'            => $resp->sucesso ?? false,
                'protocolo'          => $resp->protocolo ?? null,
            ]);

            // Ainda em processamento
            if (($resp->codigo ?? null) === 5023) {
                Log::info('ConsultNfseAction: NFS-e ainda em processamento', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo,
                ]);
                $this->setSuccess();
                return true;
            }

            $updates = [];

            if ($resp->sucesso ?? false) {
                // Autorizada
                $updates['nfse_status']    = NfeStatus::AUTHORIZED->value;
                $updates['nfse_protocol'] = $resp->protocolo ?? null;
                $updates['status']         = Status::CONFIRMED->value;
                $updates['confirmed_at']   = now();

                if (! empty($resp->numero)) {
                    $updates['document_number'] = $resp->numero;
                }
                if (! empty($resp->serie)) {
                    $updates['document_series'] = $resp->serie;
                }

                Log::info('ConsultNfseAction: NFS-e autorizada com sucesso', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'protocolo'          => $resp->protocolo ?? null,
                    'chave'              => $fiscalDocument->document_key,
                    'numero'             => $resp->numero ?? null,
                    'serie'              => $resp->serie ?? null,
                ]);
            } else {
                // Rejeitada
                $updates['nfse_status'] = NfeStatus::REJECTED->value;
                $updates['status']      = Status::CANCELLED->value;

                $errors   = $fiscalDocument->errors_messages ?? [];
                $errors[] = [
                    'at'       => now()->toDateTimeString(),
                    'codigo'   => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? 'Desconhecido',
                ];
                $updates['errors_messages'] = $errors;

                Log::warning('ConsultNfseAction: NFS-e rejeitada pela API', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo ?? null,
                    'mensagem'           => $resp->mensagem ?? null,
                ]);
            }

            $fiscalDocument->update($updates);

            if (($updates['nfse_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                Log::debug('ConsultNfseAction: iniciando geração de contas a receber', [
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                $generationService = app(AccountReceivableGenerationService::class);
                $ok = $generationService->generateFromFiscalDocument($fiscalDocument->fresh(['invoice']));

                if (! $ok) {
                    Log::warning('ConsultNfseAction: falha ao gerar contas a receber após autorização', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'invoice_id'         => $fiscalDocument->invoice_id,
                        'message'            => $generationService->getMessage(),
                        'error_code'         => $generationService->getErrorCode(),
                        'errors'             => $generationService->getErrors(),
                    ]);
                } else {
                    Log::info('ConsultNfseAction: contas a receber geradas com sucesso', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'invoice_id'         => $fiscalDocument->invoice_id,
                    ]);
                }

                app(CustomerDocumentEmailService::class)
                    ->sendFiscalDocumentAuthorized($fiscalDocument->fresh());
            }

            Log::info('ConsultNfseAction: execução concluída com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'status_final'       => $updates['nfse_status'] ?? null,
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao consultar NFS-e: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('ConsultNfseAction: exceção capturada', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'erro_classe'        => get_class($e),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

}
