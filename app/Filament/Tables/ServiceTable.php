<?php

namespace App\Filament\Tables;

use App\Models\Service;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Service::query())
            ->columns([
                TextColumn::make('name')
                    ->label('Serviço')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Unidade de Medida')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('cost')
                    ->label('Custo')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                IconColumn::make('requires_approval')
                    ->label('Requer Aprovação')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Excluído Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                ->label('Serviço')
                ->icon(Heroicon::Plus)
                ->size(Size::Small),
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
