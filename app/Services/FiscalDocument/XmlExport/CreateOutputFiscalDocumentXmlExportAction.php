<?php

namespace App\Services\FiscalDocument\XmlExport;

use App\Jobs\FetchFiscalDocumentXmlExportItemJob;
use App\Jobs\FinalizeFiscalDocumentXmlExportJob;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentXmlExport;
use App\Models\FiscalDocumentXmlExportItem;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

class CreateOutputFiscalDocumentXmlExportAction
{
    public function execute(int $companyId, int $userId, Carbon $startDate, Carbon $endDate, string $dateColumn): FiscalDocumentXmlExport
    {
        $queryService = app(OutputFiscalDocumentXmlExportQuery::class);
        $dateColumn = $queryService->normalizeDateColumn($dateColumn);
        $documents = $queryService->query($companyId, $startDate, $endDate, $dateColumn)->get();

        $export = FiscalDocumentXmlExport::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'status' => FiscalDocumentXmlExport::STATUS_PENDING,
            'date_column' => $dateColumn,
            'starts_at' => $startDate->toDateString(),
            'ends_at' => $endDate->toDateString(),
            'total_documents' => $documents->count(),
            'started_at' => now(),
        ]);

        if ($documents->isEmpty()) {
            dispatch(new FinalizeFiscalDocumentXmlExportJob($export->id));

            return $export;
        }

        $jobs = [];
        foreach ($documents as $document) {
            /** @var FiscalDocument $document */
            $item = FiscalDocumentXmlExportItem::query()->create([
                'fiscal_document_xml_export_id' => $export->id,
                'fiscal_document_id' => $document->id,
                'document_key' => (string) $document->document_key,
                'document_number' => $document->document_number,
                'status' => FiscalDocumentXmlExportItem::STATUS_PENDING,
            ]);

            $jobs[] = new FetchFiscalDocumentXmlExportItemJob($item->id);
        }

        $export->update(['status' => FiscalDocumentXmlExport::STATUS_PROCESSING]);

        $exportId = (int) $export->id;

        Bus::batch($jobs)
            ->name("Exportação XML documentos fiscais #{$export->id}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($exportId): void {
                dispatch(new FinalizeFiscalDocumentXmlExportJob($exportId));
            })
            ->dispatch();

        return $export;
    }
}
