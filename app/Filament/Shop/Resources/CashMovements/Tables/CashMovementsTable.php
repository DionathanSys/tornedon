<?php

namespace App\Filament\Shop\Resources\CashMovements\Tables;

use App\Enum\Financial\CashMovementDirection;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CashMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
            ])
            ->columns([
                Split::make([
                    Stack::make([
                        Grid::make([
                            'default' => 2,
                        ])->schema([
                            TextColumn::make('transaction_date')
                                ->label('Data')
                                ->date('d/m/Y')
                                ->weight('bold')
                                ->sortable(),
                            TextColumn::make('direction')
                                ->label('Tipo')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                                ->color(fn ($state) => $state?->color() ?? 'gray')
                                ->alignEnd(),
                        ]),
                        TextColumn::make('amount')
                            ->label('Valor')
                            ->money('BRL')
                            ->weight('bold')
                            ->color(fn ($state, $record): string => $record->direction === CashMovementDirection::OUTFLOW ? 'danger' : 'info'),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('description')
                            ->label('Descrição')
                            ->wrap()
                            ->placeholder('-'),
                        TextColumn::make('financialAccount.name')
                            ->label('Conta')
                            ->placeholder('-'),
                        TextColumn::make('financialCategory.full_name')
                            ->label('Categoria')
                            ->placeholder('-')
                            ->wrap(),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Direção')
                    ->options(CashMovementDirection::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->toolbarActions([
                CreateAction::make()
                    ->label('Movimento Manual'),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
