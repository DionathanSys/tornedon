<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Jobs\RefreshSefazDistributionDocumentJob;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class RetryRefreshAction
{
    public static function make(): Action
    {
        return Action::make('retryRefresh')
            ->label('Buscar XML novamente na SEFAZ')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (SefazDistributionDocument $record): bool => (! $record->full_xml_available
                    || ! is_string($record->full_xml_path)
                    || ! Storage::disk('local')->exists($record->full_xml_path))
                && $record->nsu !== null
                && in_array($record->manifestation_status, [
                    ManifestationStatus::ACCEPTED,
                    ManifestationStatus::FAILED,
                    ManifestationStatus::REJECTED,
                ], true))
            ->action(function (SefazDistributionDocument $record): void {
                app(SefazDistributionDocumentService::class)->markManualRefreshRequested($record);
                RefreshSefazDistributionDocumentJob::dispatch($record->id, 1);

                Notification::make()
                    ->title('Busca reenfileirada')
                    ->body('A consulta do XML completo foi enviada para a fila.')
                    ->success()
                    ->send();
            });
    }
}
