<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Tables;

use App\Enum\Financial\CashMovementDirection;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CashMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('direction')
                    ->label('Direcao')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->badge()
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descricao')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('origin_type')
                    ->label('Origem')
                    ->formatStateUsing(fn (?string $state) => $state === 'manual'
                        ? 'Manual'
                        : ($state ? Str::headline(class_basename($state)) : 'Manual'))
                    ->toggleable(),
                IconColumn::make('statement_lines_exists')
                    ->label('Conciliado')
                    ->boolean()
                    ->state(fn ($record): bool => $record->statementLines()->exists()),
                TextColumn::make('reversed_at')
                    ->label('Estornado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Direcao')
                    ->options(CashMovementDirection::toSelectArray())
                    ->native(false),
                SelectFilter::make('financial_account_id')
                    ->label('Conta')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('conciliado')
                    ->label('Conciliado')
                    ->queries(
                        true: fn ($query) => $query->whereHas('statementLines'),
                        false: fn ($query) => $query->whereDoesntHave('statementLines'),
                        blank: fn ($query) => $query,
                    )
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->visible(fn ($record): bool => $record->origin_type === 'manual'),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateHeading('Nenhum movimento financeiro encontrado');
    }
}
