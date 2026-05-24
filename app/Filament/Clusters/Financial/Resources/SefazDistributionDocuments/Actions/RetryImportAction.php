<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFiscalDocumentImportService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RetryImportAction
{
    public static function make(): Action
    {
        return Action::make('retryImport')
            ->label('Reprocessar importação')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (SefazDistributionDocument $record): bool => $record->full_xml_available
                && $record->import_status === ImportStatus::IMPORT_ERROR)
            ->action(function (SefazDistributionDocument $record): void {
                $fiscalDocument = app(SefazDistributionFiscalDocumentImportService::class)->import($record, Auth::id());

                Notification::make()
                    ->title('Importação reprocessada')
                    ->body("Documento importado para a nota de entrada #{$fiscalDocument->id}.")
                    ->success()
                    ->send();

                redirect(FiscalDocumentResource::getUrl('edit', [
                    'record' => $fiscalDocument,
                    'tenant' => Filament::getTenant(),
                ]));
            });
    }
}
