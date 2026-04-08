<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialAccounts\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FinancialAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->badge(),
                TextColumn::make('institution_name')
                    ->label('Instituicao')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('opening_balance')
                    ->label('Saldo Inicial')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('current_balance')
                    ->label('Saldo Atual')
                    ->money('BRL'),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name');
    }
}
