<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

final class ReopenIgnoredStatementLineAction
{
    public static function make(): Action
    {
        return Action::make('reopen_ignored_statement_line')
            ->label('Reabrir')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->value === 'ignored')
            ->schema(fn (Schema $schema) => $schema->components([
                Textarea::make('reason')
                    ->label('Motivo da reabertura')
                    ->rows(3)
                    ->required(),
            ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $reopened = $service->reopenIgnored($record, (int) auth()->id(), (string) $data['reason']);

                if ($service->hasError() || $reopened === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao reabrir linha.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha reaberta para conciliação.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }
}
