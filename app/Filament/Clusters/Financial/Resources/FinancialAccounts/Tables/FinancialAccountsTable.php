<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialAccounts\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
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
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('institution_name')
                    ->label('Instituição')
                    ->placeholder('-')
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('opening_balance')
                    ->label('Saldo Inicial')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('current_balance')
                    ->label('Saldo Atual')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),
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
            ->toolbarActions([
                CreateAction::make()
                    ->label('Conta Financeira')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('name');
    }
}
