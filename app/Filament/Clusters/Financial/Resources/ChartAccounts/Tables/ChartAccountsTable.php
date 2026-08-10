<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ChartAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.display_name')
                    ->label('Conta Pai')
                    ->placeholder('Raiz')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state): string => $state?->description() ?? '-')
                    ->badge(),
                IconColumn::make('is_postable')
                    ->label('Lançável')
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(\App\Enum\Financial\ChartAccountType::toSelectArray())
                    ->native(false),
                TernaryFilter::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->native(false),
                TernaryFilter::make('is_postable')
                    ->label('Lançável')
                    ->boolean()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Conta do Plano')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('Nenhuma conta cadastrada no plano');
    }
}
