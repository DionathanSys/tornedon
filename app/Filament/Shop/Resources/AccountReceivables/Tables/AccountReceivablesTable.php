<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Tables;

use App\Enum\AccountReceivable\Status;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('counterparty_label')
                    ->label('Cliente')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"))
                                ->orWhere('manual_counterparty_name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(32),
                TextColumn::make('due_date')
                    ->label('Vence')
                    ->date('d/m')
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
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
            ->toolbarActions([
                CreateAction::make()
                    ->label('Conta a Receber')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ]);
    }
}
