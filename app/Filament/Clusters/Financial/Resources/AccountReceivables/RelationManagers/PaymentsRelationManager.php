<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeletePaymentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditPaymentAction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Recebimentos';

    protected static ?string $modelLabel = 'Recebimento';

    protected static ?string $pluralModelLabel = 'Recebimentos';

    protected static string|BackedEnum|null $icon = Heroicon::Banknotes;

    #[On('refresh-payments')]
    public function refreshPayments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
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
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observações')
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
                        $livewire->dispatch('refresh-payments');
                        $livewire->dispatch('refresh-installments');
                    }),
                DeletePaymentAction::make()
                    ->iconButton()
                    ->after(function (PaymentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-payments');
                        $livewire->dispatch('refresh-installments');
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhum recebimento registrado')
            ->emptyStateDescription('Os recebimentos desta conta a receber aparecerao aqui.');
    }
}
