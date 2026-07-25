<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class CancelNfeAction
{
    use HandlesActionResponse;

    public function execute(
        FiscalDocument $fiscalDocument,
        string $justificativa = 'Cancelamento solicitado da NF-e'
    ): bool {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);
            $justificativa = trim($justificativa);

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');

                return false;
            }

            if (! $fiscalDocument->isNfeAuthorized()) {
                $this->setError('Somente NF-e autorizada pode ser cancelada.');

                return false;
            }

            if (mb_strlen($justificativa) < 15 || mb_strlen($justificativa) > 255) {
                $this->setError('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');

                return false;
            }

            $configService = app(NfeConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($fiscalDocument->company_id));

            $resp = $sdk->cancela([
                'chave'         => $fiscalDocument->document_key,
                'justificativa' => $justificativa,
            ]);

            if ($resp->sucesso ?? false) {
                $payload = is_array($fiscalDocument->nfe_payload) ? $fiscalDocument->nfe_payload : [];

                if (! empty($resp->xml)) {
                    $payload['xml_base64'] = $resp->xml;
                }

                if (! empty($resp->pdf)) {
                    $payload['pdf_base64'] = $resp->pdf;
                }

                if (! empty($resp->xml_cancelado)) {
                    $payload['xml_cancelado_base64'] = $resp->xml_cancelado;
                }

                $fiscalDocument->update([
                    'nfe_status'    => NfeStatus::CANCELED->value,
                    'nfe_protocolo' => $resp->protocolo ?? $fiscalDocument->nfe_protocolo,
                    'status'        => Status::CANCELLED->value,
                    'canceled_at'   => now(),
                ]);
                
                app(UpsertFiscalDocumentPayloadAction::class)->execute($fiscalDocument, [
                    'nfe_payload' => $payload,
                ]);
                
                $fiscalDocument->refresh();

                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfe_canceled',
                    'NF-e cancelada',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::INTEGRATION,
                    [
                        'justification' => $justificativa,
                        'protocol' => $resp->protocolo ?? null,
                    ],
                );

                Log::info('CancelNfeAction: NF-e cancelada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave' => $fiscalDocument->document_key,
                    'protocolo' => $resp->protocolo ?? null,
                ]);

                $this->setSuccess();

                return true;
            }

            $errors = $fiscalDocument->errors_messages ?? [];
            $errors[] = [
                'at' => now()->toDateTimeString(),
                'acao' => 'cancelamento',
                'codigo' => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? 'Erro ao cancelar NF-e',
                'justificativa' => $justificativa,
            ];
            $fiscalDocument->update(['errors_messages' => $errors]);

            $this->setError($resp->mensagem ?? 'Erro ao cancelar NF-e', (array) ($resp->erros ?? []));

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar NF-e: '.$e->getMessage());

            Log::error('CancelNfeAction: exceção', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'justificativa' => $justificativa,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
