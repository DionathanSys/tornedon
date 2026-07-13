<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Tables;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions\CancelProductionRequestAction;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions\DeliverProductionRequestAction;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['customer', 'accountReceivable'])
                ->withCount('items'))
            ->contentGrid([
                'default' => 1,
                '2xl' => 2,
            ])
            ->columns([
                Stack::make([
                    TextColumn::make('counterparty_label')
                        ->label('Cliente')
                        ->searchable(query: function (Builder $query, string $search): Builder {
                            return $query->where(function (Builder $query) use ($search): void {
                                $query->where('manual_counterparty_name', 'like', "%{$search}%")
                                    ->orWhere('observations', 'like', "%{$search}%")
                                    ->orWhereHas('customer', fn (Builder $customerQuery): Builder => $customerQuery->where('name', 'like', "%{$search}%"));
                            });
                        })
                        ->weight('bold')
                        ->wrap(),
                    TextColumn::make('status_order_date')
                        ->label('Status - Data Pedido')
                        ->state(fn ($record): string => sprintf(
                            '%s - %s',
                            $record->status?->description() ?? 'Sem status',
                            $record->order_date?->format('d/m/Y') ?? '-'
                        ))
                        ->color('gray'),
                    Grid::make([
                        'default' => 2,
                    ])->schema([
                        TextColumn::make('delivered_at')
                            ->label('Entrega')
                            ->formatStateUsing(fn ($state): string => $state ? $state->format('d/m/Y') : 'Pendente')
                            ->color(fn ($state): string => $state ? 'success' : 'warning')
                            ->weight('semibold'),
                    ]),
                    TextColumn::make('observations')
                        ->label('Observação')
                        ->placeholder('')
                        ->wrap()
                        ->color('gray'),
                    Grid::make([
                        'default' => 2,
                        'sm' => 4,
                    ])->schema([
                        TextColumn::make('total_amount')
                            ->label('Total')
                            ->money('BRL')
                            ->badge()
                            ->color('success'),
                        TextColumn::make('items_count')
                            ->label('Itens')
                            ->formatStateUsing(fn (int $state): string => $state . ' item(ns)')
                            ->badge()
                            ->color('gray'),
                        TextColumn::make('accountReceivable.document_number')
                            ->label('Recebível')
                            ->state(fn ($record): string => $record->accountReceivable?->document_number ?: 'Sem conta a receber')
                            ->badge()
                            ->color(fn ($record): string => $record->account_receivable_id ? 'info' : 'danger')
                            ->url(fn ($record): ?string => $record->account_receivable_id
                                ? AccountReceivableResource::getUrl('edit', ['record' => $record->account_receivable_id])
                                : null),
                    ]),
                ])->space(3),
            ])
            ->recordActions([
                DeliverProductionRequestAction::make()
                    ->button()
                    ->size(Size::Small),
                EditAction::make()
                    ->label('Editar')
                    ->button()
                    ->size(Size::Small),
                CancelProductionRequestAction::make()
                    ->button()
                    ->size(Size::Small),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->defaultSort('order_date', 'desc')
            ->searchPlaceholder('Buscar cliente ou observacao...');
    }
}
