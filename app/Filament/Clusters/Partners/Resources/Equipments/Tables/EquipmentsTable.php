<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
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
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Proprietário')
                    ->sortable()
                    ->placeholder('Sem proprietário')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->searchable()
                    ->formatStateUsing(fn($state) => $state->description())
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->disabledClick(),
                TextColumn::make('identifier')
                    ->label('Placa / Nº Série')
                    ->disabledClick()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->searchable(query: fn(Builder $q, string $s) => $q->searchByIdentifier($s)),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Editado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
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
            ])
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistColumnSearchesInSession()
            ->groups([
                Group::make('owner.name')
                    ->label('Proprietário')
                    ->collapsible(),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
