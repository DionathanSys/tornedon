<?php

namespace App\Jobs;

use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use App\Services\Fiscal\Sefaz\SefazDfeStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSefazDistributionDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_ATTEMPTS = 6;

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly int $distributionDocumentId,
        private readonly int $attemptNumber = 1,
    ) {
    }

    public function uniqueId(): string
    {
        return 'sefaz-dfe-refresh-' . $this->distributionDocumentId;
    }

    public function handle(
        SefazDfeDistributionService $distributionService,
        SefazDistributionDocumentService $documentService,
        SefazDfeStorageService $storageService,
    ): void {
        $document = SefazDistributionDocument::query()->with('company')->find($this->distributionDocumentId);
        if (! $document || ! $document->company || $document->full_xml_available || ! $document->nsu) {
            return;
        }

        try {
            $result = $distributionService->distribute($document->company, 'numero_nsu', $document->nsu);
            $rawResponsePath = $storageService->storeRawResponse($document->company, $result->rawXml);
            $foundFullXml = false;

            foreach ($result->documents as $distributedDocument) {
                $persisted = $documentService->persistFromDistribution($document->company, $distributedDocument, $rawResponsePath);

                if ($persisted?->id === $document->id && $persisted->full_xml_available) {
                    $foundFullXml = true;
                    break;
                }
            }

            if ($foundFullXml) {
                return;
            }

            if ($this->attemptNumber < self::MAX_ATTEMPTS) {
                dispatch(new self($this->distributionDocumentId, $this->attemptNumber + 1))
                    ->delay(now()->addMinutes(min(30, $this->attemptNumber * 5)));
                return;
            }

            $documentService->markRefreshFailure(
                $document,
                'O XML completo ainda não foi disponibilizado pela SEFAZ após as tentativas automáticas.',
            );
        } catch (\Throwable $exception) {
            $documentService->markRefreshFailure($document, $exception->getMessage());

            Log::error('RefreshSefazDistributionDocumentJob: falha ao consultar NSU específico', [
                'distribution_document_id' => $document->id,
                'company_id' => $document->company_id,
                'nsu' => $document->nsu,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
