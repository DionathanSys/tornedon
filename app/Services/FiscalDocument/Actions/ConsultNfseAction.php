<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
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
            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');
                return false;
            }

            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->consulta(['chave' => $fiscalDocument->document_key]);

            Log::info('ConsultNfseAction: resposta da API', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo'             => $resp->codigo ?? null,
                'sucesso'            => $resp->sucesso ?? false,
            ]);

            // Ainda em processamento
            if (($resp->codigo ?? null) === 5023) {
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

                Log::info('ConsultNfseAction: NFS-e autorizada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'protocolo'          => $resp->protocolo ?? null,
                    'chave'              => $fiscalDocument->document_key,
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

                Log::warning('ConsultNfseAction: NFS-e rejeitada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo ?? null,
                    'mensagem'           => $resp->mensagem ?? null,
                ]);
            }

            $fiscalDocument->update($updates);

            if (($updates['nfse_status'] ?? null) === NfeStatus::AUTHORIZED->value) {
                $this->syncAccountReceivablesDocumentNumber($fiscalDocument);
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao consultar NFS-e: ' . $e->getMessage());

            Log::error('ConsultNfseAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function syncAccountReceivablesDocumentNumber(FiscalDocument $fiscalDocument): void
    {
        if (! $fiscalDocument->invoice_id) {
            return;
        }

        $documentNumber = $fiscalDocument->document_number ?? $fiscalDocument->document_key;

        if (! $documentNumber) {
            return;
        }

        $fiscalDocument->invoice
            ?->accountReceivables()
            ->whereNull('document_number')
            ->update(['document_number' => $documentNumber]);
    }
}
