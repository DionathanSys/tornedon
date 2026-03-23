<?php

namespace App\Filament\Mobile\Resources\Services\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
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
                            ->prefix('Preço mínimo')
                            ->money('BRL')
                            ->placeholder('-'),
                        TextColumn::make('tax_classification')
                            ->searchable(),
                        TextColumn::make('municipal_tax_code')
                            ->searchable(),
                        TextColumn::make('nbs_code')
                            ->searchable(),
                        TextColumn::make('CreatedBy.name'),
                        TextColumn::make('updatedBy.name'),

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
            ->toolbarActions([])
            ->searchPlaceholder('Buscar por nome, código ou categoria...');
    }
}
