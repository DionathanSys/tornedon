<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Enum\AccountPayable\Status;
use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountPayables extends ListRecords
{
    protected static string $resource = AccountPayableResource::class;

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
                    Status::PARTIALLY_PAID->value,
                ]))
                ->badge(static::getResource()::getEloquentQuery()->whereIn('status', [
                    Status::PENDING->value,
                    Status::PARTIALLY_PAID->value,
                ])->count())
                ->badgeColor(Status::PENDING->color()),
            Status::OVERDUE->value => Tab::make('Vencidas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::OVERDUE->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::OVERDUE->value)->count())
                ->badgeColor(Status::OVERDUE->color()),
            Status::PAID->value => Tab::make('Pagas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::PAID->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::PAID->value)->count())
                ->badgeColor(Status::PAID->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
