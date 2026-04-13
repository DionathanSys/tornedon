<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeletePaymentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditPaymentAction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Recebimentos';

    protected static ?string $modelLabel = 'Recebimento';

    protected static ?string $pluralModelLabel = 'Recebimentos';

    protected static string|BackedEnum|null $icon = Heroicon::Banknotes;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('installment.accountReceivable.sequence_number')
                    ->label('Conta')
                    ->badge()
                    ->sortable(),
                TextColumn::make('installment.sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('interest_amount')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('fine_amount')
                    ->label('Multa')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observacoes')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('payment_date', 'desc')
            ->headerActions([])
            ->recordActions([
                EditPaymentAction::make()
                    ->iconButton()
                    ->after(function (PaymentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-page');
                    }),
                DeletePaymentAction::make()
                    ->iconButton()
                    ->after(function (PaymentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-page');
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhum recebimento registrado')
            ->emptyStateDescription('Os recebimentos das parcelas desta fatura aparecerao aqui.');
    }
}
