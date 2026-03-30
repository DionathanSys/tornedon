<?php

namespace App\Filament\Mobile\Resources\Services\Tables;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ServicesTable
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
                        Grid::make([
                            'default' => 2,
                        ])->schema([
                            TextColumn::make('service_code')
                                ->label('Código')
                                ->searchable()
                                ->placeholder('-')
                                ->color('gray'),
                            TextColumn::make('price')
                                ->label('Preço')
                                ->money('BRL')
                                ->sortable()
                                ->grow(false)
                                ->alignEnd(),
                        ]),
                        TextColumn::make('name')
                            ->label('Serviço')
                            ->columnSpanFull()
                            ->searchable()
                            ->wrap(),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('min_sale_price')
                            ->prefix('Preço mín. ')
                            ->money('BRL')
                            ->placeholder('-'),
                        TextColumn::make('tax_classification')
                            ->prefix('Class. Tributária ')
                            ->searchable(),
                        TextColumn::make('municipal_tax_code')
                            ->prefix('Cód. Tributário Mun. ')
                            ->searchable(),
                        TextColumn::make('nbs_code')
                            ->prefix('Cód. NBS ')
                            ->searchable(),

                    ]),
                ])->collapsible(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('lg'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('lg'),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('lg'),
            ])
            ->filters([
                TrashedFilter::make(),
                Filter::make('is_active')
                    ->label('Ativo')
                    ->query(fn($query) => $query->where('is_active', true))
                    ->toggle()
                    ->default(true),
            ])
            ->filtersTriggerAction(
                fn(Action $action) => $action
                    ->button()
                    ->label('Filtrar'),
            )
            ->persistFiltersInSession()
            ->deferFilters(false)
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([
                CreateAction::make()
                    ->icon(Heroicon::Plus)
                    ->label('Serviço')
                    ->size(Size::Small)
            ])
            ->searchPlaceholder('Buscar por nome, código ou categoria...');
    }
}
