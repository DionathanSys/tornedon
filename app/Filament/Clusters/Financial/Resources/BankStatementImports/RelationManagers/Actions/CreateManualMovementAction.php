<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Livewire\Component;

final class CreateManualMovementAction
{
    public static function make(): Action
    {
        return Action::make('create_manual_movement')
            ->label('Criar movimento')
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->value !== 'reconciled')
            ->schema(fn (Schema $schema) => $schema
                ->components([
                    SelectFinancialCategory::make('financial_category_id', 'cash_movement')
                        ->label('Categoria Financeira')
                        ->required(),
                    DatePicker::make('transaction_date')
                        ->label('Data do movimento')
                        ->default(fn (BankStatementLine $record) => $record->transaction_date)
                        ->required(),
                    TextInput::make('description')
                        ->label('Descrição')
                        ->default(fn (BankStatementLine $record) => $record->description)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3),
                ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->createManualMovement($record, $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao criar movimento manual.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Movimento manual criado e conciliado com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }
}
