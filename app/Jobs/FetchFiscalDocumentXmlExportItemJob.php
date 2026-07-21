<?php

namespace App\Jobs;

use App\Models\FiscalDocumentXmlExportItem;
use App\Services\FiscalDocument\XmlExport\DownloadFiscalDocumentXmlAction;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FetchFiscalDocumentXmlExportItemJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $exportItemId,
    ) {
        $this->queue = 'default';
    }

    public function handle(DownloadFiscalDocumentXmlAction $downloadAction): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = FiscalDocumentXmlExportItem::query()
            ->with(['export', 'fiscalDocument'])
            ->find($this->exportItemId);

        if (! $item || ! $item->export || ! $item->fiscalDocument) {
            return;
        }

        $item->update([
            'status' => FiscalDocumentXmlExportItem::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $document = $item->fiscalDocument;
        if ((int) $document->company_id !== (int) $item->export->company_id) {
            $this->failItem($item, 'Documento fiscal não pertence à empresa da exportação.');

            return;
        }

        $xml = $downloadAction->execute($document);
        if ($xml === null || $downloadAction->hasError()) {
            $this->failItem($item, $downloadAction->getMessage() ?: 'Não foi possível baixar o XML.');

            return;
        }

        $safeKey = preg_replace('/\D+/', '', (string) $item->document_key) ?: (string) $item->id;
        $path = "private/fiscal-document-xml-exports/{$item->fiscal_document_xml_export_id}/xmls/{$safeKey}.xml";

        Storage::disk('local')->put($path, $xml);

        $item->update([
            'status' => FiscalDocumentXmlExportItem::STATUS_COMPLETED,
            'xml_disk' => 'local',
            'xml_path' => $path,
            'error_message' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $item = FiscalDocumentXmlExportItem::query()->find($this->exportItemId);
        if (! $item) {
            return;
        }

        $this->failItem($item, Str::limit($e->getMessage(), 1000, ''));
    }

    private function failItem(FiscalDocumentXmlExportItem $item, string $message): void
    {
        $item->update([
            'status' => FiscalDocumentXmlExportItem::STATUS_FAILED,
            'error_message' => $message,
            'processed_at' => now(),
        ]);
    }
}
