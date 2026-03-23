<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListServiceOrders extends ListRecords
{
    protected static string $resource = ServiceOrderResource::class;

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Todas')
                ->badge(static::getResource()::getEloquentQuery()->count())
                ->badgeColor('gray'),
        ];

        foreach (State::cases() as $state) {
            $tabs[$state->value] = Tab::make($state->description())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $state->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', $state->value)->count())
                ->badgeColor($state->color());
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
