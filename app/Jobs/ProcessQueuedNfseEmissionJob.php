<?php

namespace App\Jobs;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\SaveFiscalDocumentErrorAction;
use App\Services\FiscalDocument\Actions\SendNfseAction;
use App\Services\FiscalDocument\FiscalEmissionPreflightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessQueuedNfseEmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $emissionGroupKey,
    ) {
    }

    public function handle(): void
    {
        $lock = Cache::lock('fiscal-emission-group:' . md5($this->emissionGroupKey), 300);

        if (! $lock->get()) {
            return;
        }

        try {
            while ($document = $this->nextQueuedDocument()) {
                $this->processDocument($document);
            }
        } finally {
            $lock->release();
        }
    }

    private function processDocument(FiscalDocument $document): void
    {
        $document->refresh();

        if (! $document->isNfseQueued()) {
            return;
        }

        $preflightService = app(FiscalEmissionPreflightService::class);
        $preflight = $preflightService->validateForSend($document);

        if ($preflight === null || $preflightService->hasError()) {
            $document->update([
                'status' => Status::PENDING->value,
                'nfse_status' => NfeStatus::PENDING->value,
                'emission_attempted_at' => now(),
            ]);

            $this->persistError(
                $document,
                'preflight',
                $preflightService->getMessage(),
                $preflightService->getErrors(),
            );

            return;
        }

        $document->update([
            'emission_attempted_at' => now(),
        ]);

        $userId = (int) ($document->updated_by ?? $document->created_by ?? 0);
        $action = new SendNfseAction($userId);
        $result = $action->execute($document, $preflight->series);

        if ($result) {
            return;
        }

        $document->refresh();

        if ($document->nfse_status === NfeStatus::QUEUED) {
            $document->update([
                'status' => Status::PENDING->value,
                'nfse_status' => NfeStatus::PENDING->value,
            ]);
        }

        $this->persistError(
            $document,
            'emitir_queue',
            $action->getMessage(),
            $action->getErrors(),
        );
    }

    private function nextQueuedDocument(): ?FiscalDocument
    {
        return FiscalDocument::query()
            ->where('document_type', 'nfse')
            ->where('nfse_status', NfeStatus::QUEUED->value)
            ->where('emission_group_key', $this->emissionGroupKey)
            ->orderBy('emission_requested_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function persistError(FiscalDocument $document, string $action, ?string $message, array $errors): void
    {
        $persistAction = new SaveFiscalDocumentErrorAction();
        $persistAction->execute($document, $message, [
            'acao' => $action,
            'erros' => $errors,
            'contexto' => [
                'emission_group_key' => $this->emissionGroupKey,
            ],
        ]);

        Log::warning('ProcessQueuedNfseEmissionJob: falha ao processar documento da fila', [
            'fiscal_document_id' => $document->id,
            'action' => $action,
            'message' => $message,
            'emission_group_key' => $this->emissionGroupKey,
        ]);
    }
}
