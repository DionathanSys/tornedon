<?php

namespace App\Jobs;

use App\Models\FiscalDocumentXmlExport;
use App\Models\FiscalDocumentXmlExportItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ZipArchive;

class FinalizeFiscalDocumentXmlExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly int $exportId,
    ) {
        $this->queue = 'default';
    }

    public function handle(): void
    {
        $export = FiscalDocumentXmlExport::query()
            ->with(['items.fiscalDocument', 'user'])
            ->find($this->exportId);

        if (! $export || in_array($export->status, [FiscalDocumentXmlExport::STATUS_COMPLETED, FiscalDocumentXmlExport::STATUS_COMPLETED_WITH_ERRORS, FiscalDocumentXmlExport::STATUS_FAILED, FiscalDocumentXmlExport::STATUS_EXPIRED], true)) {
            return;
        }

        $completedItems = $export->items
            ->where('status', FiscalDocumentXmlExportItem::STATUS_COMPLETED)
            ->filter(fn (FiscalDocumentXmlExportItem $item): bool => is_string($item->xml_path) && Storage::disk($item->xml_disk ?? 'local')->exists($item->xml_path));

        $successful = $completedItems->count();
        $failed = $export->items->where('status', FiscalDocumentXmlExportItem::STATUS_FAILED)->count();

        if ($successful === 0) {
            $export->update([
                'status' => FiscalDocumentXmlExport::STATUS_FAILED,
                'successful_documents' => 0,
                'failed_documents' => max($failed, (int) $export->total_documents),
                'error_message' => 'Nenhum XML foi gerado para o período informado.',
                'finished_at' => now(),
            ]);

            $this->notifyFailure($export->fresh(['user']));

            return;
        }

        $zipPath = "private/fiscal-document-xml-exports/{$export->id}/notas-saida-xml.zip";
        $absoluteZipPath = Storage::disk('local')->path($zipPath);
        $directory = dirname($absoluteZipPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($absoluteZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o arquivo ZIP da exportação XML.');
        }

        foreach ($completedItems as $item) {
            $disk = $item->xml_disk ?: 'local';
            $xmlPath = (string) $item->xml_path;
            $name = $this->zipEntryName($item);
            $zip->addFile(Storage::disk($disk)->path($xmlPath), $name);
        }

        $zip->close();

        $token = Str::random(48);
        $expiresAt = now()->addDay();
        $status = $failed > 0
            ? FiscalDocumentXmlExport::STATUS_COMPLETED_WITH_ERRORS
            : FiscalDocumentXmlExport::STATUS_COMPLETED;

        $export->update([
            'status' => $status,
            'successful_documents' => $successful,
            'failed_documents' => $failed,
            'zip_disk' => 'local',
            'zip_path' => $zipPath,
            'download_token' => $token,
            'download_expires_at' => $expiresAt,
            'finished_at' => now(),
        ]);

        $this->notifySuccess($export->fresh(['user']));
    }

    private function zipEntryName(FiscalDocumentXmlExportItem $item): string
    {
        $number = trim((string) $item->document_number);
        $key = preg_replace('/\D+/', '', (string) $item->document_key) ?: (string) $item->id;
        $prefix = $item->fiscalDocument?->isNfse() ? 'NFSe' : 'NFe';

        return $number !== ''
            ? "{$prefix}-{$number}-{$key}.xml"
            : "{$prefix}-{$key}.xml";
    }

    private function notifySuccess(?FiscalDocumentXmlExport $export): void
    {
        if (! $export || ! $export->user instanceof User || blank($export->download_token)) {
            return;
        }

        $url = URL::temporarySignedRoute(
            'fiscal-document-xml-exports.download',
            $export->download_expires_at,
            ['export' => $export->id, 'token' => $export->download_token],
        );

        Notification::make()
            ->title('XMLs dos documentos fiscais disponíveis')
            ->body(sprintf(
                'Exportação concluída com %d XML(s). O link expira em 24 horas.',
                (int) $export->successful_documents,
            ))
            ->success()
            ->actions([
                Action::make('download_xml_zip')
                    ->label('Baixar ZIP')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url($url, shouldOpenInNewTab: true),
            ])
            ->sendToDatabase($export->user);
    }

    private function notifyFailure(?FiscalDocumentXmlExport $export): void
    {
        if (! $export || ! $export->user instanceof User) {
            return;
        }

        Notification::make()
            ->title('Falha na exportação de XMLs')
            ->body($export->error_message ?: 'Não foi possível gerar o ZIP de XMLs dos documentos fiscais.')
            ->danger()
            ->sendToDatabase($export->user);
    }
}
