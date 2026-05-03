<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Tables;

use App\Enum\PurchaseClosing\Status;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseClosingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Referência')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('start_date')
                    ->label('Período Inicial')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Período Final')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('gross_amount')
                    ->label('Bruto')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('net_amount')
                    ->label('Líquido')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('accountPayable.id')
                    ->label('Conta a Pagar')
                    ->formatStateUsing(fn ($state): string => $state ? '#' . $state : '-')
                    ->url(fn ($record): ?string => $record->accountPayable
                        ? AccountPayableResource::getUrl('edit', ['record' => $record->accountPayable])
                        : null)
                    ->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ]);
    }
}
