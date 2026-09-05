<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Tables;

use App\Filament\Clusters\Sales\Resources\Services\Pages\Actions\DuplicateServiceAction;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('service_code')
                    ->label('Código')
                    ->searchable()
                    ->placeholder('-')
                    ->color('gray'),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $searchTerm = "%{$search}%";

                        return $query->where(function (Builder $query) use ($searchTerm) {
                            $query
                                ->where('name', 'like', $searchTerm)
                                ->orWhere('description', 'like', $searchTerm)
                                ->orWhere('nbs_code', 'like', $searchTerm)
                                ->orWhere('cnae_code', 'like', $searchTerm);
                        });
                    })
                    ->sortable()
                    ->limit(50),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->badge()
                    ->color('info')
                    ->toggleable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('requires_approval')
                    ->label('Requer Aprovação')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nbs_code')
                    ->label('NBS')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cnae_code')
                    ->label('CNAE')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado por')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->multiple()
                    ->native(false),
                TernaryFilter::make('requires_approval')
                    ->label('Requer Aprovação')
                    ->boolean()
                    ->trueLabel('Apenas que requerem aprovação')
                    ->falseLabel('Apenas que não requerem aprovação')
                    ->native(false),
                TrashedFilter::make(),
            ])
            ->recordActions([
                DuplicateServiceAction::make()
                    ->iconButton(),
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->icon(Heroicon::Plus)
                    ->label('Serviço')
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
