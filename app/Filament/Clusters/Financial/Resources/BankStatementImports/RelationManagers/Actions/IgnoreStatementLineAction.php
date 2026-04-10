<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

final class IgnoreStatementLineAction
{
    public static function make(): Action
    {
        return Action::make('ignore_statement_line')
            ->label('Ignorar')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->schema(fn (Schema $schema) => $schema->components([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->rows(3),
            ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $ignored = $service->ignore($record, auth()->id(), $data['reason'] ?? null);

                if ($service->hasError() || $ignored === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao ignorar linha.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha ignorada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }
}
