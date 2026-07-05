<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Enum\AccountReceivable\Status;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountReceivables extends ListRecords
{
    protected static string $resource = AccountReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            'open' => Tab::make('Em aberto')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    Status::PENDING->value,
                    Status::PARTIALLY_RECEIVED->value,
                ]))
                ->badge(static::getResource()::getEloquentQuery()->whereIn('status', [
                    Status::PENDING->value,
                    Status::PARTIALLY_RECEIVED->value,
                ])->count())
                ->badgeColor(Status::PENDING->color()),
            Status::OVERDUE->value => Tab::make('Vencidas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::OVERDUE->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::OVERDUE->value)->count())
                ->badgeColor(Status::OVERDUE->color()),
            Status::RECEIVED->value => Tab::make('Recebidas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::RECEIVED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::RECEIVED->value)->count())
                ->badgeColor(Status::RECEIVED->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
