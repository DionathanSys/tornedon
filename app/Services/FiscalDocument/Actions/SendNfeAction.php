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
            Log::debug('SendNfeAction: iniciando envio de NF-e', [
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id'         => $fiscalDocument->company_id,
                'customer_id'        => $fiscalDocument->customer_id,
                'nfe_status_atual'   => $fiscalDocument->nfe_status?->value,
                'serie'              => $serie,
            ]);

            // Bloqueia reenvio apenas quando já houve envio efetivo/processamento.
            if ($fiscalDocument->blocksNfeResubmission()) {
                $msgErro = 'Esta NF-e já foi enviada (status: ' . $fiscalDocument->nfe_status?->description() . ')';
                $this->setError($msgErro);
                Log::warning('SendNfeAction: tentativa de reenvio bloqueada', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'status_atual'       => $fiscalDocument->nfe_status?->value,
                ]);
                return false;
            }

            $configService = app(NfeConfigService::class);

            // Resolve série e natureza
            $serie            = $serie ?? $configService->resolveSerie($fiscalDocument->company_id);

            $rawNature = $fiscalDocument->operation_nature;
            $natureValue = $rawNature instanceof \App\Enum\FiscalDocument\OperationNature
                ? $rawNature->value
                : $rawNature;
            $operationNature  = $operationNature ?? $natureValue;

            if (empty($operationNature)) {
                $this->setError('Natureza da operação não definida. Preencha o campo antes de emitir a NF-e.');
                return false;
            }

            // ------------------------------------------------------------------
            // 1. Reservar número (somente se ainda não reservado, ex: reenvio)
            // Também cobre casos inválidos como "000000", que viram 0 no payload.
            // ------------------------------------------------------------------
            $currentNumber = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->document_number ?? ''));

            if ($currentNumber < 1) {
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

            Log::info('SendNfeAction: enviando para API IntegraNotas', [
                'fiscal_document_id' => $fiscalDocument->id,
                'numero'             => $fiscalDocument->document_number,
                'serie'              => $serie,
                'ambiente'           => $ambiente,
                'items_count'        => $fiscalDocument->items->count(),
                'valor_total'        => $payload['infNFe']['ide']['natOp'] ?? null,
            ]);

            $resp = $sdk->cria($payload);

            Log::debug('SendNfeAction: resposta da API recebida', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo'             => $resp->codigo ?? null,
                'sucesso'            => $resp->sucesso ?? false,
                'chave'              => $resp->chave ?? null,
            ]);

            // ------------------------------------------------------------------
            // 4. Processar resposta
            // ------------------------------------------------------------------
            if ($resp->sucesso && ($resp->codigo ?? null) === 5023) {
                // Lote em processamento — salva chave e aguarda webhook/polling
                $fiscalDocument->update([
                    'document_key' => $resp->chave,
                    'nfe_status'   => NfeStatus::IN_PROCESSING->value,
                    'nfe_ambiente' => $ambiente,
                    'nfe_payload'  => $payload,
                ]);

                Log::info('SendNfeAction: NF-e enviada com sucesso, aguardando processamento', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'chave'              => $resp->chave,
                    'codigo_resposta'    => $resp->codigo ?? null,
                    'ambiente'           => $ambiente,
                ]);

                // Dispara job de consulta como fallback (15s de delay)
                // dispatch(new \App\Jobs\ConsultNfeJob($fiscalDocument->id, $this->userId))
                //     ->delay(now()->addSeconds(15));

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

                $msgErro = 'Erro de validação nos dados da NF-e: ' . ($resp->mensagem ?? 'verifique os campos');
                $this->setError($msgErro, (array) ($resp->erros ?? []));
                Log::warning('SendNfeAction: erro de validação na API', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'codigo'             => $resp->codigo ?? null,
                    'mensagem'           => $resp->mensagem ?? null,
                    'erros_detalhes'     => (array) ($resp->erros ?? []),
                ]);
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

            $msgErro = $resp->mensagem ?? 'Erro ao enviar NF-e';
            $this->setError($msgErro, (array) ($resp->erros ?? []));
            Log::error('SendNfeAction: erro na resposta da API', [
                'fiscal_document_id' => $fiscalDocument->id,
                'codigo'             => $resp->codigo ?? null,
                'mensagem'           => $resp->mensagem ?? null,
                'sucesso'            => $resp->sucesso ?? false,
            ]);
            return false;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao enviar NF-e: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('SendNfeAction: exceção capturada', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id'         => $fiscalDocument->company_id,
                'exception'          => $e->getMessage(),
                'erro_classe'        => get_class($e),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
