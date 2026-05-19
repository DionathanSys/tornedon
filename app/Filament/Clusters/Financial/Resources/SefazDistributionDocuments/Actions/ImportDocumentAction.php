<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ImportDocumentAction
{
    public static function make(): Action
    {
        return Action::make('importDocument')
            ->label('Importar documento')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available
                && $record->import_status !== ImportStatus::IMPORTED
                && $record->import_status !== ImportStatus::IGNORED)
            ->action(function (SefazDistributionDocument $record): void {
                $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($record, Auth::id());

                Notification::make()
                    ->title('Documento importado')
                    ->body("DF-e importado para a nota de entrada #{$fiscalDocument->id}.")
                    ->success()
                    ->send();
            })
            ->successRedirectUrl(fn (SefazDistributionDocument $record): string => SefazDistributionDocumentResource::getUrl('view', [
                'record' => $record,
                'tenant' => Filament::getTenant(),
            ]));
    }
}
