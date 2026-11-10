<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Services\Invoice\InvoiceFinancialLinkService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceFinancialHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'receivableLinks';

    protected static ?string $title = 'Histórico financeiro';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source_receivable_id')->label('Título original'),
            TextColumn::make('origin')->label('Origem')->formatStateUsing(fn ($state) => $state === 'generated' ? 'Gerado pela fatura' : 'Preexistente vinculado'),
            TextColumn::make('snapshot.document_number')->label('Documento'),
            TextColumn::make('snapshot.due_amount_cents')->label('Valor original')->formatStateUsing(fn ($state) => 'R$ '.number_format($state / 100, 2, ',', '.')),
            TextColumn::make('linked_at')->label('Vinculado em')->dateTime('d/m/Y H:i'),
            TextColumn::make('released_at')->label('Liberado em')->dateTime('d/m/Y H:i'),
            TextColumn::make('release_reason')->label('Motivo'),
        ])->recordActions([
            Action::make('trace')->label('Rastreabilidade')->modalSubmitAction(false)->schema([
                Textarea::make('evidence')->label('Parcelas, recebimentos e movimentos/estornos')->disabled()->dehydrated(false)
                    ->default(fn ($record) => json_encode([
                        'origin' => $record->origin,
                        'original' => $record->snapshot,
                        'current_or_release' => $record->release_snapshot ?? ($record->accountReceivable ? app(InvoiceFinancialLinkService::class)->snapshot($record->accountReceivable) : null),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(18),
            ]),
        ])->headerActions([])->toolbarActions([]);
    }
}
