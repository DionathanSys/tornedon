<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Tables;

use App\Enum\Financial\CashMovementDirection;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financialCategory.full_name')
                    ->label('Categoria')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('direction')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->badge()
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('origin_label')
                    ->label('Origem')
                    ->toggleable(),
                TextColumn::make('tracking_label')
                    ->label('De / Para')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('statement_lines_exists')
                    ->label('Conciliado')
                    ->boolean()
                    ->state(fn ($record): bool => $record->statementLines()->exists()),
                TextColumn::make('reversed_at')
                    ->label('Estornado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
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
                DateRangeFilter::make('transaction_date')
                    ->label('Data Movimento')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->alwaysShowCalendar(),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->visible(fn ($record): bool => $record->origin_type === 'manual'),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Movimento Manual'),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->emptyStateHeading('Nenhum movimento financeiro encontrado');
    }
}
