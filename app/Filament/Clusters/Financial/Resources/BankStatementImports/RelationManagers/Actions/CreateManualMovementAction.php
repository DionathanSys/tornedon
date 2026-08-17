<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true)
            ->schema(fn (Schema $schema) => $schema
                ->columns(4)
                ->components([
                    Toggle::make('is_manual_counterparty')
                        ->label('Parceiro avulso')
                        ->default(true)
                        ->columnSpan(1)
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function (bool $state, Set $set): void {
                            if ($state) {
                                $set('counterparty_partner_id', null);

                                return;
                            }

                            $set('manual_counterparty_name', null);
                        }),
                    TextInput::make('manual_counterparty_name')
                        ->label('Parceiro avulso')
                        ->maxLength(255)
                        ->columnSpan(3)
                        ->hidden(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false)),
                    SelectPartner::make('counterparty_partner_id', 'all')
                        ->label('Parceiro com cadastro')
                        ->required(false)
                        ->columnSpan(3)
                        ->hidden(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false)),
                    SelectFinancialCategory::make('financial_category_id', 'cash_movement')
                        ->label('Categoria Financeira')
                        ->columnSpan(2)
                        ->required(),
                    DatePicker::make('transaction_date')
                        ->label('Data do movimento')
                        ->default(fn (BankStatementLine $record) => $record->transaction_date)
                        ->columnSpan(2)
                        ->required(),
                    TextInput::make('description')
                        ->label('Descrição')
                        ->default(fn (BankStatementLine $record) => $record->description)
                        ->columnSpanFull()
                        ->required(),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
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
