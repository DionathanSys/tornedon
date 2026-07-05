<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Tables;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
        ->stackedOnMobile()
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('counterparty_label')
                    ->label('Cliente')
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($inner) use ($search): void {
                            $inner->whereHas('customer', fn ($partnerQuery) => $partnerQuery->where('name', 'like', "%{$search}%"))
                                ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Status $state): string => $state->description())
                    ->color(fn (Status $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('order_date')
                    ->label('Pedido')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('delivered_at')
                    ->label('Entregue em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('accountReceivable.document_number')
                    ->label('Conta a Receber')
                    ->placeholder('Pendente')
                    ->url(fn ($record): ?string => $record->account_receivable_id
                        ? AccountReceivableResource::getUrl('edit', ['record' => $record->account_receivable_id])
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->native(false),
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Pedido para Produção')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
