<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyCreditCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Cartão')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('issuer')
                    ->label('Emissor')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('last_four')
                    ->label('Final')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('closing_day')
                    ->label('Fech.')
                    ->sortable(),
                TextColumn::make('due_day')
                    ->label('Venc.')
                    ->sortable(),
                TextColumn::make('statement_cutoff_business_days')
                    ->label('Corte D.U.')
                    ->sortable(),
                TextColumn::make('defaultFinancialAccount.name')
                    ->label('Conta Padrão')
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Cartão Corporativo'),
            ])
            ->defaultSort('name');
    }
}
