<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\NfeInvalidationRequest;
use App\Services\Fiscal\NfeConfigService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessNfeInvalidationRequestAction
{
    use HandlesActionResponse;

    public function execute(NfeInvalidationRequest $request, int $userId, ?string $justification = null): bool
    {
        if ($request->isCompleted()) {
            Log::info('ProcessNfeInvalidationRequestAction: Inutilização já processada anteriormente.', [
                'request_id' => $request->id,
                'serie' => $request->serie,
                'number_start' => $request->number_start,
                'number_end' => $request->number_end,
                'company_id' => $request->company_id,
                'user_id' => $userId,
            ]);
            $this->setSuccess();
            return true;
        }

        try {
            $configService = app(NfeConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams((int) $request->company_id));

            $payload = [
                'numero_inicial' => (string) $request->number_start,
                'numero_final' => (string) $request->number_end,
                'serie' => $request->serie,
                'justificativa' => trim((string) ($justification ?: $request->justification)),
            ];

            $response = $sdk->inutiliza($payload);

            if (! ($response->sucesso ?? false)) {
                $message = $response->mensagem ?? 'Erro ao inutilizar numeração NF-e.';

                $request->update([
                    'status' => 'failed',
                    'processed_by' => $userId,
                    'processed_at' => now(),
                    'response_payload' => (array) $response,
                    'error_message' => $message,
                    'justification' => $payload['justificativa'],
                ]);

                $this->setError($message, (array) ($response->erros ?? []));
                return false;
            }

            DB::transaction(function () use ($request, $userId, $payload, $response): void {
                $request->update([
                    'status' => 'completed',
                    'processed_by' => $userId,
                    'processed_at' => now(),
                    'response_payload' => (array) $response,
                    'error_message' => null,
                    'justification' => $payload['justificativa'],
                ]);
            });

            Log::info('ProcessNfeInvalidationRequestAction: Inutilização processada com sucesso.', [
                'request_id' => $request->id,
                'serie' => $request->serie,
                'number_start' => $request->number_start,
                'number_end' => $request->number_end,
                'company_id' => $request->company_id,
                'user_id' => $userId,
            ]);
            $this->setSuccess();
            return true;
        } catch (\Throwable $e) {
            Log::error('ProcessNfeInvalidationRequestAction: erro ao inutilizar numeração', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'request_id' => $request->id,
                'exception' => $e->getMessage(),
            ]);

            $request->update([
                'status' => 'failed',
                'processed_by' => $userId,
                'processed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $this->setError('Erro ao inutilizar numeração NF-e: ' . $e->getMessage());
            return false;
        }
    }
}
