<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Tables;

use App\Enum\AccountReceivable\Status;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->contentGrid([
                'default' => 1,
                '2xl' => 2,
            ])
            ->columns([
                Split::make([
                    Stack::make([
                        Grid::make([
                            'default' => 2,
                        ])->schema([
                            TextColumn::make('counterparty_label')
                                ->label('Cliente')
                                ->searchable(query: function (Builder $query, string $search): Builder {
                                    return $query->where(function (Builder $query) use ($search): void {
                                        $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                                            ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                                    });
                                })
                                ->weight('bold')
                                ->wrap(),
                            TextColumn::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                                ->color(fn ($state) => $state?->color() ?? 'gray')
                                ->alignEnd(),
                        ]),
                        TextColumn::make('due_amount')
                            ->label('Valor')
                            ->money('BRL')
                            ->weight('bold')
                            ->color('success')
                            ->sortable(),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('due_date')
                            ->label('Vencimento')
                            ->date('d/m/Y')
                            ->sortable(),
                        TextColumn::make('document_number')
                            ->label('Documento')
                            ->placeholder('-'),
                        TextColumn::make('description')
                            ->label('Descrição')
                            ->placeholder('-')
                            ->wrap(),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->defaultSort('due_date')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->toolbarActions([
                CreateAction::make()
                    ->label('Conta a Receber')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ]);
    }
}
