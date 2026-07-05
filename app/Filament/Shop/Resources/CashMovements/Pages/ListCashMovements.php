<?php

namespace App\Filament\Shop\Resources\CashMovements\Pages;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Shop\Resources\CashMovements\CashMovementResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCashMovements extends ListRecords
{
    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            CashMovementDirection::INFLOW->value => Tab::make('Entradas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('direction', CashMovementDirection::INFLOW->value))
                ->badge(static::getResource()::getEloquentQuery()->where('direction', CashMovementDirection::INFLOW->value)->count())
                ->badgeColor(CashMovementDirection::INFLOW->color()),
            CashMovementDirection::OUTFLOW->value => Tab::make('Saídas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('direction', CashMovementDirection::OUTFLOW->value))
                ->badge(static::getResource()::getEloquentQuery()->where('direction', CashMovementDirection::OUTFLOW->value)->count())
                ->badgeColor(CashMovementDirection::OUTFLOW->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return CashMovementDirection::INFLOW->value;
    }
}
