<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Cancela uma NFS-e autorizada via API IntegraNotas.
 */
class CancelNfseAction
{
    use HandlesActionResponse;

    public function execute(
        FiscalDocument $fiscalDocument,
        string $codigoCancelamento = '9',
        string $motivoCancelamento = 'Cancelamento solicitado'
    ): bool
    {
        try {
            $codigoCancelamento = trim($codigoCancelamento);
            $motivoCancelamento = trim($motivoCancelamento);

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');
                return false;
            }

            if (! $fiscalDocument->isNfseAuthorized()) {
                $this->setError('Somente NFS-e autorizada pode ser cancelada.');
                return false;
            }

            if (! in_array($codigoCancelamento, ['1', '2', '9'], true)) {
                $this->setError('Código de cancelamento inválido.');
                return false;
            }

            if ($motivoCancelamento === '' || mb_strlen($motivoCancelamento) > 80) {
                $this->setError('O motivo do cancelamento deve ter entre 1 e 80 caracteres.');
                return false;
            }

            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->cancela([
                'chave' => $fiscalDocument->document_key,
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
            ]);

            if ($resp->sucesso ?? false) {
                $fiscalDocument->update([
                    'nfse_status' => NfeStatus::CANCELED->value,
                    'status'      => Status::CANCELLED->value,
                    'canceled_at' => now(),
                ]);

                Log::info('CancelNfseAction: NFS-e cancelada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave'              => $fiscalDocument->document_key,
                    'codigo_cancelamento' => $codigoCancelamento,
                ]);

                $this->setSuccess();
                return true;
            }

            $errors   = $fiscalDocument->errors_messages ?? [];
            $errors[] = [
                'at'       => now()->toDateTimeString(),
                'acao'     => 'cancelamento',
                'codigo'   => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? 'Erro ao cancelar NFS-e',
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
            ];
            $fiscalDocument->update(['errors_messages' => $errors]);

            $this->setError($resp->mensagem ?? 'Erro ao cancelar NFS-e', (array) ($resp->erros ?? []));
            return false;

        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar NFS-e: ' . $e->getMessage());

            Log::error('CancelNfseAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
