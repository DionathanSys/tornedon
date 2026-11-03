<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class RefreshStatementSuggestionsAction
{
    public static function make(): Action
    {
        return Action::make('refresh_suggestions')
            ->label('Atualizar sugestões')
            ->icon('heroicon-o-arrow-path')
            ->iconButton()
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true)
            ->action(function (BankStatementLine $record): void {
                $service = app(ResolveBankStatementLineService::class);
                $service->refreshSuggestions($record);

                Notification::make()
                    ->title($service->getMessage())
                    ->body($service->hasError() ? 'Não foi possível atualizar as sugestões. Tente novamente.' : null)
                    ->{$service->hasError() ? 'danger' : 'success'}()
                    ->send();
            });
    }
}
