<?php

namespace App\Filament\Tables;

use App\Enum\Product\Unit;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => Product::query()
                ->where('company_id', Filament::getTenant()->id)
                ->where('is_custom_manufacturing', true))
            ->columns([
                TextColumn::make('product_code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $searchTerm = "%{$search}%";

                        return $query->where(function (Builder $query) use ($searchTerm) {
                            $query
                                ->where('name', 'like', $searchTerm)
                                ->orWhere('barcode', 'like', $searchTerm)
                                ->orWhere('manufacturer_code', 'like', $searchTerm)
                                ->orWhereRaw('CAST(external_reference_codes AS CHAR) LIKE ?', [$searchTerm]);
                        });
                    })
                    ->sortable()
                    ->limit(50),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                TextColumn::make('unit')
                    ->label('Unidade')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos')
                    ->native(false)
                    ->default(true),
                SelectFilter::make('category_id')
                    ->label('Categoria')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn($query) => $query
                            ->where('company_id', Filament::getTenant()->id)
                            ->orderBy('name')
                    )
                    ->searchable()
                    ->preload()
                    ->multiple()
                    ->native(false),
                SelectFilter::make('unit')
                    ->label('Unidade')
                    ->options(Unit::toSelectArray())
                    ->multiple()
                    ->native(false),
                SelectFilter::make('has_stock_control')
                    ->label('Controla estoque?')
                    ->options([
                        1 => 'Sim',
                        0 => 'Não',
                    ])
                    ->multiple()
                    ->native(false),
                SelectFilter::make('is_custom_manufacturing')
                    ->label('Fabricação própria?')
                    ->options([
                        1 => 'Sim',
                        0 => 'Não',
                    ])
                    ->multiple()
                    ->native(false),
                TrashedFilter::make(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
