<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardPaymentProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Perfil')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand')
                    ->label('Bandeira')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('acquirer')
                    ->label('Adquirente')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('fee_percent')
                    ->label('Taxa %')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                TextColumn::make('fee_fixed')
                    ->label('Taxa fixa')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('settlement_days')
                    ->label('D+X')
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Perfil de Cartao'),
            ])
            ->defaultSort('name');
    }
}
