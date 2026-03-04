<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o envio de uma NF-e à API IntegraNotas:
 *
 *  1. Reserva o número (ReserveNfeNumberAction) — atômico
 *  2. Monta o payload (BuildNfePayloadAction)
 *  3. Chama $nfe->cria($payload) via SDK
 *  4. Em código 5023 (lote em processamento): salva chave, status, payload, ambiente
 *  5. Em 5001/5002 (erro de validação): salva erros em errors_messages
 *  6. Dispara ConsultNfeJob como fallback de polling (webhook é o canal primário)
 */
class SendNfeAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(FiscalDocument $fiscalDocument, ?string $serie = null, ?string $operationNature = null): bool
    {
        try {
            // Impede reenvio de NF-e já em processamento ou autorizada
            if ($fiscalDocument->nfe_status !== null && ! $fiscalDocument->isRejeitado()) {
                $this->setError('Esta NF-e já foi enviada (status: ' . $fiscalDocument->nfe_status?->description() . ').');
                return false;
            }

            $configService = app(NfeConfigService::class);

            // Resolve série e natureza
            $serie            = $serie ?? $configService->resolveSerie($fiscalDocument->company_id);
            $operationNature  = $operationNature ?? ($fiscalDocument->operation_nature ?? 'VENDA');

            // ------------------------------------------------------------------
            // 1. Reservar número (somente se ainda não reservado, ex: reenvio)
            // ------------------------------------------------------------------
            if (empty($fiscalDocument->document_number)) {
                $reserveAction = new ReserveNfeNumberAction();
                if (! $reserveAction->execute($fiscalDocument, $serie, $operationNature)) {
                    $this->setError($reserveAction->getMessage());
                    return false;
                }

                $fiscalDocument->refresh();
            }

            // ------------------------------------------------------------------
            // 2. Montar payload
            // ------------------------------------------------------------------
            $buildAction = new BuildNfePayloadAction();
            $payload     = $buildAction->execute($fiscalDocument);

            if ($payload === null) {
                $this->setError($buildAction->getMessage());
                return false;
            }

            // ------------------------------------------------------------------
            // 3. Enviar via SDK
            // ------------------------------------------------------------------
            $ambiente = $configService->resolveAmbiente($fiscalDocument->company_id);
            $sdk      = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($fiscalDocument->company_id));

            Log::info('SendNfeAction: enviando NF-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'numero'             => $fiscalDocument->document_number,
                'serie'              => $serie,
                'ambiente'           => $ambiente,
            ]);

            $resp = $sdk->cria($payload);

            // ------------------------------------------------------------------
            // 4. Processar resposta
            // ------------------------------------------------------------------
            if ($resp->sucesso && ($resp->codigo ?? null) === 5023) {
                // Lote em processamento — salva chave e aguarda webhook/polling
                $fiscalDocument->update([
                    'document_key' => $resp->chave,
                    'nfe_status'   => NfeStatus::EM_PROCESSAMENTO->value,
                    'nfe_ambiente' => $ambiente,
                    'nfe_payload'  => $payload,
                ]);

                Log::info('SendNfeAction: NF-e enviada, aguardando processamento', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave'              => $resp->chave,
                    'ambiente'           => $ambiente,
                ]);

                // Dispara job de consulta como fallback (15s de delay)
                dispatch(new \App\Jobs\ConsultNfeJob($fiscalDocument->id, $this->userId))
                    ->delay(now()->addSeconds(15));

                $this->setSuccess('NF-e enviada e em processamento na SEFAZ.');
                return true;
            }

            // Erros de validação dos dados (5001 = emitente, 5002 = dados gerais)
            if (in_array($resp->codigo ?? null, [5001, 5002])) {
                $errors   = $fiscalDocument->errors_messages ?? [];
                $errors[] = [
                    'at'      => now()->toDateTimeString(),
                    'codigo'  => $resp->codigo ?? null,
                    'mensagem'=> $resp->mensagem ?? 'Erro de validação',
                    'erros'   => (array) ($resp->erros ?? []),
                ];

                $fiscalDocument->update(['errors_messages' => $errors]);

                $this->setError('Erro de validação nos dados da NF-e: ' . ($resp->mensagem ?? 'verifique os campos'));
                return false;
            }

            // Qualquer outro erro
            $errors   = $fiscalDocument->errors_messages ?? [];
            $errors[] = [
                'at'      => now()->toDateTimeString(),
                'codigo'  => $resp->codigo ?? null,
                'mensagem'=> $resp->mensagem ?? 'Erro desconhecido',
            ];
            $fiscalDocument->update(['errors_messages' => $errors]);

            $this->setError($resp->mensagem ?? 'Erro ao enviar NF-e');
            return false;

        } catch (\Exception $e) {
            $this->setError('Erro ao enviar NF-e: ' . $e->getMessage());

            Log::error('SendNfeAction: exceção', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
