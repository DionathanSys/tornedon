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
        try {
            $event = $service->ingest(
                $connection,
                $request->all(),
                $request->getContent(),
            );

            return response()->json([
                'ok' => $event !== null,
                'event_id' => $event?->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('BankSlipWebhookController: falha ao receber webhook', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json(['ok' => false]);
        }
    }
}
