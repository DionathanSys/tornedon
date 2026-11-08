<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\FiscalCancellationPolicy;
use App\Services\FiscalDocument\FiscalDocumentIssueService;
use App\Traits\HandlesActionResponse;
use CloudDfe\SdkPHP\Nfse;
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
        string $motivoCancelamento = 'Cancelamento solicitado',
        ?int $userId = null,
    ): bool {
        try {
            $fiscalDocument->refresh();
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);
            $codigoCancelamento = trim($codigoCancelamento);
            $motivoCancelamento = trim($motivoCancelamento);
            $isNationalNfse = $this->resolveNfseModel($fiscalDocument) === NfseModel::NACIONAL->value;

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');

                return false;
            }

            if ($isNationalNfse) {
                $documentKey = preg_replace('/\D/', '', (string) $fiscalDocument->document_key);

                if (strlen($documentKey) !== 44) {
                    $this->setError('A chave de acesso da NFS-e nacional deve conter 44 dígitos.');

                    return false;
                }
            }

            if (! $fiscalDocument->isNfseAuthorized()) {
                $this->setError('Somente NFS-e autorizada pode ser cancelada.');

                return false;
            }

            if (! in_array($codigoCancelamento, ['1', '2', '9'], true)) {
                $this->setError('Código de cancelamento inválido.');

                return false;
            }

            $minReasonLength = $isNationalNfse ? 15 : 1;
            $maxReasonLength = $isNationalNfse ? 255 : 80;

            if ($motivoCancelamento === '' || mb_strlen($motivoCancelamento) < $minReasonLength || mb_strlen($motivoCancelamento) > $maxReasonLength) {
                $this->setError("O motivo do cancelamento deve ter entre {$minReasonLength} e {$maxReasonLength} caracteres.");

                return false;
            }

            $configService = app(NfseConfigService::class);
            $eligibility = app(FiscalCancellationPolicy::class)->evaluate($fiscalDocument);
            if (! $eligibility['allowed']) {
                $this->setError($eligibility['message']);

                return false;
            }
            $sdk = new Nfse($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->cancela([
                'chave' => $fiscalDocument->document_key,
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
            ]);

            if ($resp->sucesso ?? false) {
                $fiscalDocument->update([
                    'nfse_status' => NfeStatus::CANCELED->value,
                    'status' => Status::CANCELLED->value,
                    'canceled_at' => now(),
                    'canceled' => true,
                    'pending' => false,
                    'confirmed' => false,
                    'canceled_by' => $userId,
                ]);
                $fiscalDocument->refresh();

                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_canceled',
                    'NFS-e cancelada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::INTEGRATION,
                    [
                        'cancel_code' => $codigoCancelamento,
                        'cancel_reason' => $motivoCancelamento,
                    ],
                );

                Log::info('CancelNfseAction: NFS-e cancelada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave' => $fiscalDocument->document_key,
                    'codigo_cancelamento' => $codigoCancelamento,
                ]);

                $this->setSuccess();

                return true;
            }

            $errors = $fiscalDocument->errors_messages ?? [];
            $errors[] = [
                'at' => now()->toDateTimeString(),
                'acao' => 'cancelamento',
                'codigo' => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? 'Erro ao cancelar NFS-e',
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
            ];
            $fiscalDocument->update(['errors_messages' => $errors]);
            app(FiscalDocumentIssueService::class)->open($fiscalDocument, 'cancellation_refused', $resp->mensagem ?? 'Cancelamento recusado pela prefeitura.', $userId);

            $this->setError($resp->mensagem ?? 'Erro ao cancelar NFS-e', (array) ($resp->erros ?? []));

            return false;

        } catch (\Exception $e) {
            app(FiscalDocumentIssueService::class)->open($fiscalDocument, 'cancellation_uncertain', 'Falha durante a solicitação de cancelamento. Consulte o provedor antes de repetir. '.$e->getMessage(), $userId);
            $this->setError('Erro ao cancelar NFS-e: '.$e->getMessage());

            Log::error('CancelNfseAction: exceção', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo_cancelamento' => $codigoCancelamento,
                'motivo_cancelamento' => $motivoCancelamento,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function resolveNfseModel(FiscalDocument $fiscalDocument): string
    {
        return $fiscalDocument->nfse_model instanceof NfseModel
            ? $fiscalDocument->nfse_model->value
            : trim((string) $fiscalDocument->nfse_model);
    }
}
