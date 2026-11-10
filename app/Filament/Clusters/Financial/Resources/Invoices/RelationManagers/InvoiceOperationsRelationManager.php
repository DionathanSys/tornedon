<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceOperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Operações da fatura';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type')->label('Operação')->formatStateUsing(fn ($state) => $state->description()),
            TextColumn::make('status')->label('Situação')->formatStateUsing(fn ($state) => $state->description())->badge(),
            TextColumn::make('last_message')->label('Andamento')->wrap(),
            TextColumn::make('created_at')->label('Solicitada em')->dateTime('d/m/Y H:i'),
            TextColumn::make('completed_at')->label('Concluída em')->dateTime('d/m/Y H:i'),
        ])->recordActions([
            Action::make('steps')->label('Etapas')->modalSubmitAction(false)->schema([
                Textarea::make('history')->label('Etapas e justificativa')->disabled()->dehydrated(false)->rows(16)
                    ->default(fn ($record) => $record->justification."\n\n".json_encode($record->steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            ]),
        ])->headerActions([])->toolbarActions([])->defaultSort('id', 'desc');
    }
}
