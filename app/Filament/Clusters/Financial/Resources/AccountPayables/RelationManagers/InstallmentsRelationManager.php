<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    protected static string|BackedEnum|null $icon = Heroicon::QueueList;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence_number')
            ->columns([
                TextColumn::make('sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('original_amount')
                    ->label('Valor Original')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_amount')
                    ->label('Valor Atual')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('paid_amount')
                    ->label('Valor Pago')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('interest_amount')
                    ->label('Juros')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fine_amount')
                    ->label('Multa')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->description() ?? '-')
                    ->color(fn($state) => $state?->color() ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('paid_date')
                    ->label('Data Pgto.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sequence_number')
            ->headerActions([])
            ->recordActions([
                RegisterInstallmentPaymentAction::make()
                    ->iconButton()
                    ->after(function (InstallmentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-page');
                    }),
                EditInstallmentAction::make()
                    ->iconButton()
                    ->after(function (InstallmentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-page');
                    }),
                DeleteInstallmentAction::make()
                    ->iconButton()
                    ->after(function (InstallmentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-page');
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma parcela gerada')
            ->emptyStateDescription('As parcelas desta conta a pagar aparecerão aqui.');
    }
}
