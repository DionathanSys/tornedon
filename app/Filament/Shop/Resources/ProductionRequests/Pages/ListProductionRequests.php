<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProductionRequests extends ListRecords
{
    protected static string $resource = ProductionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos')
                ->badgeColor('gray'),
            Status::OPEN->value => Tab::make('Abertos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::OPEN->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::OPEN->value)->count())
                ->badgeColor(Status::OPEN->color()),
            Status::DELIVERED->value => Tab::make('Entregues')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::DELIVERED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::DELIVERED->value)->count())
                ->badgeColor(Status::DELIVERED->color()),
            Status::CANCELLED->value => Tab::make('Cancelados')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::CANCELLED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::CANCELLED->value)->count())
                ->badgeColor(Status::CANCELLED->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Status::OPEN->value;
    }
}
