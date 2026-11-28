<?php

namespace App\Filament\Operation\Resources\Equipments\Tables;

use App\Enum\Equipment\Type;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EquipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Descrição')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('owner.name')
                    ->label('Proprietário')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sem proprietário'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->sortable(),
                TextColumn::make('identifier')
                    ->label('Placa / Nº Série')
                    ->searchable(query: fn (Builder $query, string $search) => $query->searchByIdentifier($search))
                    ->placeholder('-')
                    ->disabledClick(),
                TextColumn::make('mark')
                    ->label('Marca')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')
                    ->label('Modelo')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('owner_id')
                    ->label('Proprietário')
                    ->relationship(
                        'owner',
                        'name',
                        modifyQueryUsing: function (Builder $query) {
                            $tenant = Filament::getTenant();

                            return $query
                                ->whereHas('companies', function (Builder $subQuery) use ($tenant) {
                                    $subQuery->where('company_id', $tenant->id);
                                });
                        }
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Type::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Equipamento')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('id', 'desc');
    }
}
