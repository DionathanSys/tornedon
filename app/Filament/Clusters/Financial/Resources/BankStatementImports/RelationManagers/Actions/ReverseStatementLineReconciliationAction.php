<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Enum\Financial\BankStatementLineStatus;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

final class ReverseStatementLineReconciliationAction
{
    public static function make(): Action
    {
        return Action::make('reverse_statement_line_reconciliation')
            ->label('Desfazer conciliação')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status === BankStatementLineStatus::RECONCILED)
            ->schema(fn (Schema $schema) => $schema->components([
                Textarea::make('reason')
                    ->label('Motivo do desfazimento')
                    ->rows(3)
                    ->required(),
            ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $reversed = $service->reverseReconciliation($record, auth()->id(), (string) $data['reason']);

                Notification::make()
                    ->title($reversed ? $service->getMessageUser() : ($service->getMessageUser() ?: 'Erro ao desfazer conciliação.'))
                    ->{$reversed ? 'success' : 'danger'}()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }
            });
    }
}
