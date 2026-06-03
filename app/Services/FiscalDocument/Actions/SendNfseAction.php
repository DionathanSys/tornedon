<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\NfseConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestra o envio de uma NFS-e à API IntegraNotas:
 *
 *  1. Atribui temporariamente o menor número livre do grupo
 *  2. Monta o payload (BuildNfsePayloadAction) — strategy por modelo
 *  3. Chama $nfse->cria($payload) via SDK
 *  4. Em código 5023 (lote em processamento): confirma consumo do RPS, salva chave e status
 *  5. Em falhas antes da aceitação: limpa a atribuição do número
 *  6. Dispara ConsultNfseJob como fallback de polling
 */
class SendNfseAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(
        FiscalDocument $fiscalDocument,
        ?string $serie = null,
        ?string $scenarioCode = null,
        ?\App\Domain\DTO\Fiscal\ScenarioContext $scenarioContext = null
    ): bool {
        $apiCallStarted = false;
        $reservedNow = false;

        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($fiscalDocument);

            Log::debug('SendNfseAction: iniciando envio de NFS-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id' => $fiscalDocument->company_id,
                'customer_id' => $fiscalDocument->customer_id,
                'nfse_status_atual' => $fiscalDocument->nfse_status?->value,
                'serie' => $serie,
                'scenario_code' => $scenarioCode,
            ]);

            if (
                $fiscalDocument->isNfseInProcessing()
                || $fiscalDocument->isNfseAuthorized()
                || $fiscalDocument->isNfseCanceled()
            ) {
                $msgErro = 'Esta NFS-e já foi enviada (status: '.$fiscalDocument->nfse_status?->description().')';
                $this->setError($msgErro);
                Log::warning('SendNfseAction: tentativa de reenvio bloqueada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'status_atual' => $fiscalDocument->nfse_status?->value,
                ]);

                return false;
            }

            $configService = app(NfseConfigService::class);

            $serie = $serie ?? $configService->resolveSerie($fiscalDocument->company_id);

            // ------------------------------------------------------------------
            // 1. Reservar número RPS no momento do envio real
            // ------------------------------------------------------------------
            $currentNumber = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));

            if ($currentNumber < 1) {
                $this->reserveNumberForAttempt($fiscalDocument, $serie);
                $fiscalDocument->refresh();
                $reservedNow = true;
            }

            if (! $this->ensureDocumentUsesCurrentLastRps($fiscalDocument)) {
                return false;
            }

            // ------------------------------------------------------------------
            // 2. Montar payload
            // ------------------------------------------------------------------
            $buildAction = new BuildNfsePayloadAction;
            $payload = $buildAction->execute($fiscalDocument);

            if ($payload === null) {
                $this->setError($buildAction->getMessage());

                return false;
            }

            unset($payload['servico']['valor_recebido']);

            // ------------------------------------------------------------------
            // 3. Enviar via SDK
            // ------------------------------------------------------------------
            $ambiente = $configService->resolveAmbiente($fiscalDocument->company_id);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams(
                $fiscalDocument->company_id,
                NfseConfigService::OPERATION_CREATE,
            ));

            Log::info('SendNfseAction: enviando para API IntegraNotas', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number' => $fiscalDocument->rps_number,
                'serie' => $serie,
                'modelo' => $fiscalDocument->nfse_model,
                'ambiente' => $ambiente,
                'items_count' => $fiscalDocument->items->count(),
                'valor_total' => $payload['servico']['valor_servicos'] ?? 0,
                'scenario_code' => $scenarioCode,
            ]);

            $apiCallStarted = true;
            $resp = $sdk->cria($payload);

            Log::debug('SendNfseAction: resposta da API recebida', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo' => $resp->codigo ?? null,
                'sucesso' => $resp->sucesso ?? false,
                'chave' => $resp->chave ?? null,
            ]);

            // ------------------------------------------------------------------
            // 4. Processar resposta
            // ------------------------------------------------------------------
            if ($resp->sucesso && ($resp->codigo ?? null) === 5023) {
                $fiscalDocument->update([
                    'document_key' => $resp->chave,
                    'nfse_status' => NfeStatus::IN_PROCESSING->value,
                    'nfse_payload' => $payload,
                ]);
                $fiscalDocument->refresh();

                $audit->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.nfse_sent',
                    'NFS-e enviada para processamento',
                    $before,
                    $audit->snapshot($fiscalDocument),
                    $this->userId,
                    AuditSource::INTEGRATION,
                    ['scenario_code' => $scenarioCode]
                );

                Log::info('SendNfseAction: NFS-e enviada, aguardando processamento', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave' => $resp->chave,
                    'ambiente' => $ambiente,
                ]);

                dispatch(new \App\Jobs\ConsultNfseJob($fiscalDocument->id, $this->userId))
                    ->delay(now()->addSeconds(15));

                $this->setSuccess();

                return true;
            }

            // Erros de validação dos dados
            if (in_array($resp->codigo ?? null, [5001, 5002])) {
                $errors = $fiscalDocument->errors_messages ?? [];
                $baseMessage = $resp->mensagem ?? 'Erro de validação';

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
                            'scenario_code' => $scenarioCode,
                        ];
                    }
                } else {
                    $errors[] = [
                        'at' => now()->toDateTimeString(),
                        'codigo' => $resp->codigo ?? null,
                        'mensagem' => $baseMessage,
                        'erros' => [],
                        'scenario_code' => $scenarioCode,
                    ];
                }

                Log::debug('SendNfseAction: erro de validação na API', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? null,
                    'erros_detalhes' => (array) ($resp->erros ?? []),
                    'resp' => $resp,
                ]);

                $fiscalDocument->update(['errors_messages' => $errors]);

                $msgErro = 'Erro de validação nos dados da NFS-e: '.($resp->mensagem ?? 'verifique os campos');
                $this->setError($msgErro, (array) ($resp->erros ?? []));
                Log::warning('SendNfseAction: erro de validação na API', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $resp->mensagem ?? null,
                    'erros_detalhes' => (array) ($resp->erros ?? []),
                ]);

                $fiscalDocument->update([
                    'status' => \App\Enum\FiscalDocument\Status::PENDING->value,
                    'nfse_status' => NfeStatus::PENDING->value,
                ]);

                return false;
            }

            // Qualquer outro erro
            $errors = $fiscalDocument->errors_messages ?? [];
            $baseMessage = $resp->mensagem ?? 'Erro desconhecido';

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
                        'scenario_code' => $scenarioCode,
                    ];
                }
            } else {
                $errors[] = [
                    'at' => now()->toDateTimeString(),
                    'codigo' => $resp->codigo ?? null,
                    'mensagem' => $baseMessage,
                    'erros' => (array) ($resp->erros ?? []),
                    'scenario_code' => $scenarioCode,
                ];
            }
            $fiscalDocument->update(['errors_messages' => $errors]);

            $msgErro = $resp->mensagem ?? 'Erro ao enviar NFS-e';
            $this->setError($msgErro, (array) ($resp->erros ?? []));
            Log::error('SendNfseAction: erro na resposta da API', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo' => $resp->codigo ?? null,
                'mensagem' => $resp->mensagem ?? null,
                'sucesso' => $resp->sucesso ?? false,
            ]);

            $this->markForReconciliation(
                $fiscalDocument,
                sprintf(
                    'Falha ambígua ao enviar NFS-e para API. RPS %s/%s preservado até conciliação.',
                    $fiscalDocument->rps_series,
                    $fiscalDocument->rps_number,
                )
            );

            return false;

        } catch (Throwable $e) {
            $msgErro = 'Erro ao enviar NFS-e: '.$e->getMessage();
            $this->setError($msgErro);

            Log::error('SendNfseAction: exceção capturada', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id' => $fiscalDocument->company_id,
                'exception' => $e->getMessage(),
                'erro_classe' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($apiCallStarted) {
                $this->markForReconciliation(
                    $fiscalDocument,
                    sprintf(
                        'Exceção ambígua após tentativa de envio da NFS-e. RPS %s/%s preservado até conciliação.',
                        $fiscalDocument->rps_series,
                        $fiscalDocument->rps_number,
                    )
                );
            }

            return false;
        } finally {
            if ($reservedNow && ! $apiCallStarted) {
                $this->releaseReservedNumberAssignment($fiscalDocument);
            }
        }
    }

    private function reserveNumberForAttempt(FiscalDocument $fiscalDocument, string $serie): void
    {
        $result = NfseSequence::nextNumber(
            (int) $fiscalDocument->company_id,
            $serie,
        );

        $fiscalDocument->update([
            'rps_number' => (string) $result['number'],
            'rps_series' => $serie,
            'nfse_sequence_id' => $result['sequence_id'],
        ]);
    }

    private function ensureDocumentUsesCurrentLastRps(FiscalDocument $fiscalDocument): bool
    {
        $currentNumber = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));

        if ($currentNumber < 1 || $serie === '') {
            $this->setError('Documento sem RPS reservado para envio.');

            return false;
        }

        if (NfseSequence::isCurrentLastNumber((int) $fiscalDocument->company_id, $serie, $currentNumber)) {
            return true;
        }

        $this->markForReconciliation(
            $fiscalDocument,
            sprintf(
                'RPS %s/%s não é mais o maior número reservado da série. Emissão bloqueada até conciliação.',
                $serie,
                $currentNumber,
            )
        );

        $this->setError('O RPS reservado no documento não é mais o maior número da série. Faça a conciliação antes de reenviar.');

        return false;
    }

    private function releaseReservedNumberAssignment(FiscalDocument $fiscalDocument): void
    {
        $currentNumber = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));

        if ($currentNumber < 1 || $serie === '' || $fiscalDocument->isNfseInProcessing() || $fiscalDocument->isNfseAuthorized()) {
            return;
        }

        $released = NfseSequence::releaseLastNumberIfAvailable(
            (int) $fiscalDocument->company_id,
            $serie,
            $currentNumber,
            (int) $fiscalDocument->id,
        );

        if (! $released) {
            $this->markForReconciliation(
                $fiscalDocument,
                sprintf(
                    'Falha ao desfazer a reserva do RPS %s/%s antes da chamada da API. Conciliação necessária.',
                    $serie,
                    $currentNumber,
                )
            );

            return;
        }

        $fiscalDocument->update([
            'rps_number' => null,
            'rps_series' => null,
            'nfse_sequence_id' => null,
        ]);
    }

    private function markForReconciliation(FiscalDocument $fiscalDocument, string $reason): void
    {
        $action = app(ReconcileNfseRpsSequenceAction::class);
        $action->execute($fiscalDocument->fresh(), $reason, false);
    }
}
