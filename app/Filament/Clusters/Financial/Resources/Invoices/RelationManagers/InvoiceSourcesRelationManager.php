<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    protected static ?string $title = 'Histórico da composição';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('confirmation.revision')->label('Revisão')->placeholder('Remoção do rascunho'),
            TextColumn::make('source_type')->label('Origem')->formatStateUsing(fn (string $state): string => match ($state) {
                'requisition' => 'Requisição', 'service_order' => 'OS', 'production_order' => 'OP', default => $state,
            }),
            TextColumn::make('source_number')->label('Número')->searchable(),
            TextColumn::make('historical_items')->label('Itens na época')->state(fn ($record): string => collect($record->snapshot['items'])->map(fn (array $item): string => $item['description'].' × '.$item['quantity'])->implode('; '))->wrap(),
            TextColumn::make('snapshot.total_amount')->label('Valor histórico')->formatStateUsing(fn ($state): string => 'R$ '.number_format($state / 100, 2, ',', '.')),
            TextColumn::make('linked_at')->label('Registrado em')->dateTime('d/m/Y H:i'),
            TextColumn::make('released_at')->label('Liberado em')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextColumn::make('release_reason')->label('Motivo')->formatStateUsing(fn (?string $state): string => $state === 'draft_removal' ? 'Remoção do rascunho' : ($state ?? '—')),
        ])->defaultSort('id', 'desc')->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
