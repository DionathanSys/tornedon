<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Tables;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions\CancelProductionRequestAction;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions\DeliverProductionRequestAction;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
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
                    Split::make([
                        Stack::make([
                            TextColumn::make('summary')
                                ->label('Pedido')
                                ->state(fn ($record): string => sprintf('%s - %s', $record->number, $record->counterparty_label))
                                ->searchable(query: function (Builder $query, string $search): Builder {
                                    return $query->where(function (Builder $query) use ($search): void {
                                        $query->where('number', 'like', "%{$search}%")
                                            ->orWhere('manual_counterparty_name', 'like', "%{$search}%")
                                            ->orWhere('observations', 'like', "%{$search}%")
                                            ->orWhereHas('customer', fn (Builder $customerQuery): Builder => $customerQuery->where('name', 'like', "%{$search}%"));
                                    });
                                })
                                ->weight('bold')
                                ->wrap(),
                            TextColumn::make('counterparty_label')
                                ->label('Cliente')
                                ->color('gray')
                                ->wrap(),
                        ]),
                        TextColumn::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (Status $state): string => $state->description())
                            ->color(fn (Status $state): string => $state->color()),
                    ]),
                    Grid::make([
                        'default' => 2,
                    ])->schema([
                        TextColumn::make('order_date')
                            ->label('Pedido')
                            ->date('d/m/Y')
                            ->weight('semibold'),
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
                        ->color('gray')
                        ->visible(fn ($record): bool => filled($record->observations)),
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
                        TextColumn::make('payment_method')
                            ->label('Pagamento')
                            ->formatStateUsing(fn (?PaymentMethod $state): string => $state?->description() ?? 'Sem forma')
                            ->badge()
                            ->color(fn (?PaymentMethod $state): string => $state?->color() ?? 'danger'),
                        TextColumn::make('payment_condition')
                            ->label('Condição')
                            ->formatStateUsing(fn (?PaymentCondition $state): string => $state?->description() ?? 'Sem condição')
                            ->badge()
                            ->color(fn (?PaymentCondition $state): string => $state?->color() ?? 'gray'),
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
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('payment_method')
                    ->label('Pagamento')
                    ->options(PaymentMethod::toSelectArray())
                    ->native(false),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label('Filtrar')
            )
            ->persistFiltersInSession()
            ->deferFilters(false)
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
            ->searchPlaceholder('Buscar cliente, numero, observacao ou pedido...');
    }
}
