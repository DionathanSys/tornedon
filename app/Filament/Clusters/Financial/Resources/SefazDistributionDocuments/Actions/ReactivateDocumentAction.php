<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ReactivateDocumentAction
{
    public static function make(): Action
    {
        return Action::make('reactivateDocument')
            ->label('Reativar documento')
            ->icon('heroicon-o-eye')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn(SefazDistributionDocument $record): bool => $record->import_status === ImportStatus::IGNORED)
            ->action(function (SefazDistributionDocument $record): void {
                app(SefazDistributionDocumentService::class)->reactivateDocument($record, Auth::id());

                Notification::make()
                    ->title('Documento reativado')
                    ->success()
                    ->send();
            });
    }
}
