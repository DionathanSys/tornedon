<?php

namespace App\Http\Controllers;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Fiscal\NfeConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe notificações da API IntegraNotas após processamento de NF-e e NFS-e.
 *
 * A IntegraNotas exige:
 *   - Retorno HTTP 200 sempre (mesmo em erros internos nossos)
 *   - Sem validação de autenticação via header Bearer
 *   - POST
 *
 * A validação é feita via campo 'signature' do payload contra o
 * CompanyPreference 'integranotas.webhook_secret' da empresa emitente.
 *
 * O tipo de documento (NF-e ou NFS-e) é detectado automaticamente pelo
 * document_type do FiscalDocument localizado pela chave de acesso.
 */
class NfeWebhookController extends Controller
{
    public function handle(Request $request, NfeConfigService $configService): JsonResponse
    {
        try {
            $payload = $request->all();

            Log::info('NfeWebhookController: payload recebido', [
                'chave'  => $payload['chave'] ?? null,
                'origem' => $payload['origem'] ?? null,
                'payload' => $payload,
            ]);

            // ------------------------------------------------------------------
            // Notificação de teste de comunicação — apenas confirma recebimento
            // ------------------------------------------------------------------
            if (($payload['origem'] ?? null) === 'TESTE') {
                Log::info('NfeWebhookController: notificação de teste recebida');
                return response()->json(['ok' => true]);
            }

            $chave = $payload['chave'] ?? null;

            if (! $chave) {
                Log::warning('NfeWebhookController: payload sem chave', ['payload' => $payload]);
                return response()->json(['ok' => true]); // HTTP 200 sempre
            }

            // ------------------------------------------------------------------
            // Localiza o documento pela chave de acesso
            // ------------------------------------------------------------------
            $doc = FiscalDocument::where('document_key', $chave)->first();

            if (! $doc) {
                Log::warning('NfeWebhookController: documento não encontrado', ['chave' => $chave]);
                return response()->json(['ok' => true]);
            }

            // ------------------------------------------------------------------
            // Valida assinatura (opcional — se configurada)
            // ------------------------------------------------------------------
            $secret = $configService->resolveWebhookSecret($doc->company_id);
            if ($secret && ($payload['signature'] ?? null) !== $secret) {
                Log::warning('NfeWebhookController: assinatura inválida', [
                    'company_id' => $doc->company_id,
                    'chave'      => $chave,
                ]);
                return response()->json(['ok' => true]);
            }

            // ------------------------------------------------------------------
            // Processa status
            // ------------------------------------------------------------------
            $this->processarRetorno($doc, $payload);

        } catch (\Exception $e) {
            // Nunca retornar erro HTTP — IntegraNotas não reagenda em 2xx
            Log::error('NfeWebhookController: exceção ao processar webhook', [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function processarRetorno(FiscalDocument $doc, array $payload): void
    {
        // Detecta o tipo de documento para atualizar os campos corretos
        $isNfse = $doc->document_type === DocumentModel::NFSE;

        $statusField    = $isNfse ? 'nfse_status'    : 'nfe_status';
        $protocoloField = $isNfse ? 'nfse_protocol'  : 'nfe_protocolo';
        $logPrefix      = $isNfse ? 'NFS-e'           : 'NF-e';

        $status = $this->normalizeStatus($payload['status'] ?? null); // 'autorizado' | 'cancelado' | null (rejeitado)

        $updates = [];

        if ($status === 'autorizado') {
            $updates[$statusField]    = NfeStatus::AUTHORIZED->value;
            $updates[$protocoloField] = $payload['protocolo'] ?? null;
            $updates['status']        = Status::CONFIRMED->value;
            $updates['confirmed_at']  = now();

            if (! empty($payload['numero'])) {
                $updates['document_number'] = $payload['numero'];
            }
            if (! empty($payload['serie'])) {
                $updates['document_series'] = $payload['serie'];
            }

            Log::info("NfeWebhookController: {$logPrefix} autorizada via webhook", [
                'fiscal_document_id' => $doc->id,
                'protocolo'          => $payload['protocolo'] ?? null,
            ]);

        } elseif ($status === 'cancelado') {
            $updates[$statusField]  = NfeStatus::CANCELED->value;
            $updates['status']      = Status::CANCELLED->value;
            $updates['canceled_at'] = now();

            Log::info("NfeWebhookController: {$logPrefix} cancelada via webhook", [
                'fiscal_document_id' => $doc->id,
            ]);

        } else {
            // Rejeição
            $updates[$statusField] = NfeStatus::REJECTED->value;
            $updates['status']     = Status::CANCELLED->value;

            $errors   = $doc->errors_messages ?? [];
            $errors[] = [
                'at'       => now()->toDateTimeString(),
                'origem'   => 'webhook',
                'codigo'   => $payload['codigo'] ?? null,
                'mensagem' => $payload['mensagem'] ?? "Rejeitada",
            ];
            $updates['errors_messages'] = $errors;

            Log::warning("NfeWebhookController: {$logPrefix} rejeitada via webhook", [
                'fiscal_document_id' => $doc->id,
                'mensagem'           => $payload['mensagem'] ?? null,
            ]);
        }

        $doc->update($updates);

        if ($status === 'autorizado') {
            $generationService = app(AccountReceivableGenerationService::class);
            $ok = $generationService->generateFromFiscalDocument($doc->fresh(['invoice']));

            if (! $ok) {
                Log::warning('NfeWebhookController: falha ao gerar contas a receber após autorização', [
                    'fiscal_document_id' => $doc->id,
                    'invoice_id'         => $doc->invoice_id,
                    'message'            => $generationService->getMessage(),
                    'error_code'         => $generationService->getErrorCode(),
                    'errors'             => $generationService->getErrors(),
                ]);
            }

        }
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($status));

        return match ($normalized) {
            'autorizado', 'autorizada' => 'autorizado',
            'cancelado', 'cancelada' => 'cancelado',
            default => null,
        };
    }
}
