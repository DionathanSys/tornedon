<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Tables;

use App\Enum\AccountReceivable\Status;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_number')
                    ->label('Nº Documento')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(Status $state): string => $state->description())
                    ->color(fn(Status $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->label('Valor a Receber')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Valor Recebido')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('paid')
                    ->label('Recebido')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('paid_date')
                    ->label('Data Recebimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_method')
                    ->label('Forma Pagamento')
                    ->formatStateUsing(fn($state): string => $state?->description() ?? '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_date', 'asc');
    }
}
