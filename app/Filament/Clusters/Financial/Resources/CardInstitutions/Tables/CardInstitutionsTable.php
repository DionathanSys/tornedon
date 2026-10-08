<?php

namespace App\Filament\Clusters\Financial\Resources\CardInstitutions\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CardInstitutionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Instituição')
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
                ToggleColumn::make('is_default')
                    ->label('Padrão')
                    ->disabled(fn ($record): bool => ! $record->active),
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
                CreateAction::make()->label('Instituição de cartão')
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'company_id' => Filament::getTenant()->id,
                    ]),
            ])
            ->defaultSort('name');
    }
}
