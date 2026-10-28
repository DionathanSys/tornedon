<?php

namespace App\Http\Controllers;

use App\Models\BankAccountConnection;
use App\Services\Financial\Banking\BankSlipWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class BankSlipWebhookController extends Controller
{
    public function handle(
        Request $request,
        BankAccountConnection $connection,
        BankSlipWebhookService $service,
    ): JsonResponse {
        $context = [
            'connection_id' => $connection->id,
            'company_id' => $connection->company_id,
            'identification' => $request->input('identificacao'),
            'event_type' => $request->input('evento'),
            'provider_event_id' => $request->input('evento_id'),
            'provider_status_code' => $request->input('status.codigo'),
        ];
        Log::info('BankSlipWebhookController: webhook recebido', $context);

        try {
            $token = data_get($connection->credentials, 'webhook_token');
            if (filled($token) && ! hash_equals((string) $token, (string) $request->header('X-Webhook-Token'))) {
                Log::warning('BankSlipWebhookController: token rejeitado', $context + [
                    'reason' => $request->hasHeader('X-Webhook-Token') ? 'token_mismatch' : 'token_missing',
                ]);

                return response()->json(['ok' => false]);
            }
            if ($request->input('evento') === 'TESTE') {
                $valid = $service->validateTest($connection, $request->all(), $request->getContent());
                Log::info('BankSlipWebhookController: teste de webhook validado', $context + ['valid' => $valid]);

                return response()->json(['ok' => $valid]);
            }
            $event = $service->ingest(
                $connection,
                $request->all(),
                $request->getContent(),
            );

            Log::info('BankSlipWebhookController: recepcao finalizada', $context + [
                'accepted' => $event !== null,
                'event_id' => $event?->id,
                'event_model' => $event ? $event::class : null,
            ]);

            return response()->json([
                'ok' => $event !== null,
                'event_id' => $event?->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('BankSlipWebhookController: falha ao receber webhook', $context + [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json(['ok' => false], 503);
        }
    }
}
