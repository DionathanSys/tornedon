<?php

namespace App\Jobs;

use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use App\Services\Fiscal\Sefaz\SefazRecepcaoEventoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ManifestSefazDistributionDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_TECHNICAL_ATTEMPTS = 3;

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly int $distributionDocumentId,
        private readonly int $attemptNumber = 1,
    ) {
    }

    public function uniqueId(): string
    {
        return 'sefaz-dfe-manifest-' . $this->distributionDocumentId;
    }

    public function handle(
        SefazRecepcaoEventoService $recepcaoEventoService,
        SefazDistributionDocumentService $documentService,
    ): void {
        $document = SefazDistributionDocument::query()->with('company')->find($this->distributionDocumentId);
        if (! $document || ! $document->company || $document->full_xml_available) {
            return;
        }

        try {
            $documentService->markManifestationSent($document, [
                'requested_at' => now()->toIso8601String(),
                'event_type' => '210210',
                'attempt_number' => $this->attemptNumber,
            ]);

            $result = $recepcaoEventoService->manifestScience($document->company, $document->document_key);
            $documentService->markManifestationResult($document->fresh(), $result);

            if (($result['success'] ?? false) === true) {
                RefreshSefazDistributionDocumentJob::dispatch($document->id, 1)
                    ->delay(now()->addMinutes(5));
            }
        } catch (\Throwable $exception) {
            $shouldRetry = $this->attemptNumber < self::MAX_TECHNICAL_ATTEMPTS;

            $documentService->markManifestationFailure(
                $document,
                $exception->getMessage(),
                $this->attemptNumber,
                $shouldRetry,
            );

            if ($shouldRetry) {
                dispatch(new self($document->id, $this->attemptNumber + 1))
                    ->delay(now()->addMinutes($this->attemptNumber * 10));
            }

            Log::error('ManifestSefazDistributionDocumentJob: falha ao manifestar documento', [
                'distribution_document_id' => $document->id,
                'company_id' => $document->company_id,
                'document_key' => $document->document_key,
                'error' => $exception->getMessage(),
                'attempt_number' => $this->attemptNumber,
                'will_retry' => $shouldRetry,
            ]);
        }
    }
}
