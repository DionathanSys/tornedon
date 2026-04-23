<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages;

use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use App\Services\Fiscal\Sefaz\SefazUploadedXmlService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListSefazDistributionDocuments extends ListRecords
{
    protected static string $resource = SefazDistributionDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadXml')
                ->label('Upload XML')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Registrar XML na inbox de DF-e')
                ->form([
                    FileUpload::make('xml_file')
                        ->label('Arquivo XML')
                        ->acceptedFileTypes(['application/xml', 'text/xml', 'text/plain'])
                        ->disk('local')
                        ->directory('sefaz/distribution/uploads')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $path = $data['xml_file'] ?? null;

                    if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                        throw new \RuntimeException('O arquivo XML enviado não foi encontrado no storage temporário.');
                    }

                    $xml = Storage::disk('local')->get($path);
                    $company = Filament::getTenant();

                    if (! $company) {
                        throw new \RuntimeException('Não foi possível identificar a empresa ativa para registrar o XML.');
                    }

                    $record = app(SefazUploadedXmlService::class)->register($company, $xml, basename($path));
                    Storage::disk('local')->delete($path);

                    Notification::make()
                        ->title('XML registrado')
                        ->body("O XML foi registrado no DF-e detectado {$record->document_number}/{$record->document_series}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
