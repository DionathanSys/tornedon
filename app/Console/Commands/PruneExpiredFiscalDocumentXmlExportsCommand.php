<?php

namespace App\Console\Commands;

use App\Models\FiscalDocumentXmlExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PruneExpiredFiscalDocumentXmlExportsCommand extends Command
{
    protected $signature = 'fiscal-document-xml-exports:prune-expired';

    protected $description = 'Remove ZIPs/XMLs expirados de exportações de XML de documentos fiscais.';

    public function handle(): int
    {
        if (! Schema::hasTable('fiscal_document_xml_exports')) {
            $this->info('Tabela fiscal_document_xml_exports não encontrada. Nada para remover.');

            return self::SUCCESS;
        }

        $exports = FiscalDocumentXmlExport::query()
            ->whereNotNull('download_expires_at')
            ->where('download_expires_at', '<=', now())
            ->whereIn('status', [
                FiscalDocumentXmlExport::STATUS_COMPLETED,
                FiscalDocumentXmlExport::STATUS_COMPLETED_WITH_ERRORS,
            ])
            ->get();

        foreach ($exports as $export) {
            Storage::disk($export->zip_disk ?: 'local')->deleteDirectory("private/fiscal-document-xml-exports/{$export->id}");

            $export->update([
                'status' => FiscalDocumentXmlExport::STATUS_EXPIRED,
                'zip_path' => null,
                'download_token' => null,
            ]);
        }

        $this->info("Exportações expiradas removidas: {$exports->count()}");

        return self::SUCCESS;
    }
}
