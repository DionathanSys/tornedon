<?php

namespace App\Jobs;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\SaveFiscalDocumentErrorAction;
use App\Services\FiscalDocument\Actions\SendNfeAction;
use App\Services\FiscalDocument\FiscalEmissionPreflightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessQueuedNfeEmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly string $emissionGroupKey,
    ) {}

    public function handle(): void
    {
        $lock = Cache::lock('fiscal-emission-group:'.md5($this->emissionGroupKey), 300);

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

        if (! $document->isNfeQueued()) {
            return;
        }

        $preflightService = app(FiscalEmissionPreflightService::class);
        $preflight = $preflightService->validateForSend($document);

        if ($preflight === null || $preflightService->hasError()) {
            $document->update([
                'status' => Status::PENDING->value,
                'nfe_status' => NfeStatus::PENDING->value,
                'emission_attempted_at' => now(),
            ]);

            $this->persistError(
                $document,
                'preflight',
                $preflightService->getMessage(),
                $preflightService->getErrors(),
                $preflight->scenarioCode ?? null
            );

            return;
        }

        $document->update([
            'emission_attempted_at' => now(),
        ]);

        $userId = (int) ($document->updated_by ?? $document->created_by ?? 0);
        $action = new SendNfeAction($userId);
        $result = $action->execute(
            $document,
            $preflight->series,
            $preflight->operationNature,
            $preflight->scenarioCode,
            $preflight->scenarioContext
        );

        if ($result) {
            return;
        }

        $document->refresh();

        if ($document->nfe_status === NfeStatus::QUEUED) {
            $document->update([
                'status' => Status::PENDING->value,
                'nfe_status' => NfeStatus::PENDING->value,
            ]);
        }

        $this->persistError(
            $document,
            'emitir_queue',
            $action->getMessage(),
            $action->getErrors(),
            $preflight->scenarioCode
        );
    }

    private function nextQueuedDocument(): ?FiscalDocument
    {
        return FiscalDocument::query()
            ->where('document_type', DocumentModel::NFE->value)
            ->where('nfe_status', NfeStatus::QUEUED->value)
            ->where('emission_group_key', $this->emissionGroupKey)
            ->orderBy('emission_requested_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function persistError(FiscalDocument $document, string $action, ?string $message, array $errors, ?string $scenarioCode = null): void
    {
        $persistAction = new SaveFiscalDocumentErrorAction;
        $persistAction->execute($document, $message, [
            'acao' => $action,
            'erros' => $errors,
            'contexto' => [
                'emission_group_key' => $this->emissionGroupKey,
                'scenario_code' => $scenarioCode,
            ],
        ]);

        Log::warning('ProcessQueuedNfeEmissionJob: falha ao processar documento da fila', [
            'fiscal_document_id' => $document->id,
            'action' => $action,
            'message' => $message,
            'emission_group_key' => $this->emissionGroupKey,
            'scenario_code' => $scenarioCode,
        ]);
    }
}
