<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Models\FiscalDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class CorrectNfeAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $justificativa, ?int $sequencial = null): bool
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);
            $justificativa = trim($justificativa);

            if (empty($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');

                return false;
            }

            if (! $fiscalDocument->isNfeAuthorized()) {
                $this->setError('Somente NF-e autorizada pode receber carta de correção.');

                return false;
            }

            if (mb_strlen($justificativa) < 15 || mb_strlen($justificativa) > 1000) {
                $this->setError('A justificativa da carta de correção deve ter entre 15 e 1000 caracteres.');

                return false;
            }

            if ($sequencial !== null && $sequencial < 1) {
                $this->setError('O sequencial da carta de correção deve ser maior que zero.');

                return false;
            }

            $configService = app(NfeConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($fiscalDocument->company_id));

            $payload = [
                'chave' => $fiscalDocument->document_key,
                'justificativa' => $justificativa,
            ];

            if ($sequencial !== null) {
                $payload['sequencial'] = $sequencial;
            }

            $resp = $sdk->correcao($payload);

            if ($resp->sucesso ?? false) {
                $documentPayload = is_array($fiscalDocument->nfe_payload) ? $fiscalDocument->nfe_payload : [];
                $corrections = is_array(data_get($documentPayload, 'correcoes')) ? $documentPayload['correcoes'] : [];

                $correctionData = [
                    'justificativa' => $justificativa,
                    'sequencial' => $resp->numero_carta_correcao ?? $sequencial,
                    'protocolo' => $resp->protocolo ?? null,
                    'data_hora_evento' => $resp->data_hora_evento ?? null,
                    'xml_base64' => $resp->xml_carta_correcao ?? null,
                    'pdf_base64' => $resp->pdf_carta_correcao ?? null,
                ];

                $corrections[] = array_filter($correctionData, static fn (mixed $value): bool => $value !== null && $value !== '');

                $documentPayload['correcoes'] = $corrections;

                $fiscalDocument->update([
                    'nfe_protocolo' => $resp->protocolo ?? $fiscalDocument->nfe_protocolo,
                    'nfe_payload' => $documentPayload,
                ]);
                $fiscalDocument->refresh();

                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfe_corrected',
                    'Carta de correção da NF-e emitida',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    null,
                    AuditSource::INTEGRATION,
                    [
                        'justification' => $justificativa,
                        'protocol' => $resp->protocolo ?? null,
                        'correction_number' => $resp->numero_carta_correcao ?? $sequencial,
                    ],
                );

                Log::info('CorrectNfeAction: carta de correção emitida', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave' => $fiscalDocument->document_key,
                    'protocolo' => $resp->protocolo ?? null,
                    'numero_carta_correcao' => $resp->numero_carta_correcao ?? $sequencial,
                ]);

                $this->setSuccess();

                return true;
            }

            $errors = $fiscalDocument->errors_messages ?? [];
            $errors[] = [
                'at' => now()->toDateTimeString(),
                'acao' => 'carta_correcao',
                'codigo' => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? 'Erro ao emitir carta de correção da NF-e',
                'justificativa' => $justificativa,
                'sequencial' => $sequencial,
            ];
            $fiscalDocument->update(['errors_messages' => $errors]);

            $this->setError($resp->mensagem ?? 'Erro ao emitir carta de correção da NF-e', (array) ($resp->erros ?? []));

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro ao emitir carta de correção da NF-e: '.$e->getMessage());

            Log::error('CorrectNfeAction: exceção', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'justificativa' => $justificativa,
                'sequencial' => $sequencial,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
