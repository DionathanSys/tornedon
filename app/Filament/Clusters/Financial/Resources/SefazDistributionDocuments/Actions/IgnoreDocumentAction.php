<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class IgnoreDocumentAction
{
    public static function make(): Action
    {
        return Action::make('ignoreDocument')
            ->label('Ignorar documento')
            ->icon('heroicon-o-eye-slash')
            ->color('gray')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn(SefazDistributionDocument $record): bool => $record->import_status !== ImportStatus::IMPORTED
                && $record->import_status !== ImportStatus::IGNORED)
            ->action(function (SefazDistributionDocument $record, array $data): void {
                app(SefazDistributionDocumentService::class)->ignoreDocument($record, (string) $data['reason'], Auth::id());

                Notification::make()
                    ->title('Documento ignorado')
                    ->success()
                    ->send();
            });
    }
}
