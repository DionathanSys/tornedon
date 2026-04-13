<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\AccountReceivable\Status;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class AccountReceivablesRelationManager extends RelationManager
{
    protected static string $relationship = 'accountReceivables';

    protected static ?string $title = 'Contas à Receber';

    protected static ?string $modelLabel = 'Conta à Receber';

    protected static ?string $pluralModelLabel = 'Contas à Receber';

    protected static string|BackedEnum|null $icon = Heroicon::ArrowTrendingUp;

    #[On('invoice-confirmed')]
    public function refreshAccountReceivables(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_number')
            ->columns([
                TextColumn::make('sequence_number')
                    ->label('Seq.')
                    ->badge()
                    ->sortable(),
                TextColumn::make('document_number')
                    ->label('Documento')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('due_amount')
                    ->label('Valor Devido')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('paid_amount')
                    ->label('Valor Pago')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_method')
                    ->label('Forma de Pagto')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?Status $state) => $state?->description() ?? '-')
                    ->color(fn (?Status $state) => $state?->color() ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('sequence_number')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma conta a receber vinculada')
            ->emptyStateDescription('As contas a receber desta fatura aparecerao aqui.');
    }
}
