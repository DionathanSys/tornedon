<?php

namespace App\Filament\Mobile\Resources\Equipments\Tables;

use App\Enum\Equipment\Type;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MobileEquipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                '2xl' => 3,
            ])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')
                            ->label('Descrição')
                            ->searchable()
                            ->weight('bold')
                            ->wrap(),
                        TextColumn::make('identifier')
                            ->label('Placa / Nº Série')
                            ->placeholder('-')
                            ->searchable(query: fn(Builder $query, string $search) => $query->searchByIdentifier($search))
                            ->color('gray'),
                        TextColumn::make('owner.name')
                            ->label('Proprietário')
                            ->placeholder('Sem proprietário'),
                        TextColumn::make('type')
                            ->label('Tipo')
                            ->formatStateUsing(fn(?Type $state): string => $state?->description() ?? '-')
                            ->badge(),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i')
                            ->color('gray'),
                    ]),
                ])->collapsible(),
            ])
            ->filters([
                SelectFilter::make('owner_id')
                    ->label('Proprietário')
                    ->relationship(
                        'owner',
                        'name',
                        modifyQueryUsing: function (Builder $query) {
                            $tenant = Filament::getTenant();

                            return $query->whereHas('companies', function (Builder $subQuery) use ($tenant) {
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
            ->filtersTriggerAction(
                fn(Action $action) => $action
                    ->button()
                    ->label('Filtrar'),
            )
            ->persistFiltersInSession()
            ->deferFilters(false)
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->button(),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->toolbarActions([
                CreateAction::make()
                    ->label('Equipamento')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->searchPlaceholder('Buscar por descrição ou placa / nº série...');
    }
}
