<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FinancialCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('name')
                    ->label('Categoria')
                    ->searchable(['name', 'description'])
                    ->sortable(['name'])
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('parent.name')
                    ->label('Categoria Pai')
                    ->searchable(['parent.name'])
                    ->sortable(['parent.name'])
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('usage')
                    ->label('Uso')
                    ->state(fn ($record): string => collect([
                        $record->allow_payable ? 'Despesa' : null,
                        $record->allow_receivable ? 'Receita' : null,
                        $record->allow_cash_movement ? 'Transação' : null,
                    ])->filter()->implode(', '))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('updated_at')
                    ->label('Atualizada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->label('Categoria Financeira')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('sort_order')
            ->emptyStateHeading('Nenhuma categoria financeira cadastrada');
    }
}
