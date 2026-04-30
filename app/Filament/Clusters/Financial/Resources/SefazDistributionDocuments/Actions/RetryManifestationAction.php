<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class RetryManifestationAction
{
    public static function make(): Action
    {
        return Action::make('retryManifestation')
            ->label('Tentar manifestação novamente')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn(SefazDistributionDocument $record): bool => ! $record->full_xml_available
                && in_array($record->manifestation_status, [
                    ManifestationStatus::FAILED,
                    ManifestationStatus::REJECTED,
                ], true))
            ->action(function (SefazDistributionDocument $record): void {
                app(SefazDistributionDocumentService::class)->prepareManualManifestationRetry($record);
                ManifestSefazDistributionDocumentJob::dispatch($record->id, 1);

                Notification::make()
                    ->title('Manifestação reenfileirada')
                    ->body('A tentativa manual de manifestação foi enviada para a fila.')
                    ->success()
                    ->send();
            });
    }
}
