<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\IssueBankSlipAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    protected static string|BackedEnum|null $icon = Heroicon::QueueList;

    #[On('invoice-confirmed')]
    #[On('refresh-installments')]
    public function refreshInstallments(): void
    {
        $this->resetTable();
    }

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
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40)
                    ->tooltip(fn ($state, $record) => $record->description)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sinceTooltip()
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
                TextColumn::make('received_amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('received_date')
                    ->label('Data Receb.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sinceTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('financialAccount.name')
                    ->label('Conta financeira')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestBankSlip.status')
                    ->label('Boleto')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('latestBankSlip.pdf_url')
                    ->label('PDF boleto')
                    ->url(fn (?string $state): ?string => filled($state) ? $state : null, shouldOpenInNewTab: true)
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Abrir PDF' : '-')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('account_receivable_id')
            ->headerActions([])
            ->recordActions([
                IssueBankSlipAction::make()
                    ->tooltip('Emitir boleto')
                    ->iconButton(),
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
            ->emptyStateHeading('Sem registros');
    }
}
