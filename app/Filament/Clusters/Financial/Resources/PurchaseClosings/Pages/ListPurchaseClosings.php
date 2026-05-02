<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages;

use App\Enum\PurchaseClosing\Status;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\PurchaseClosingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseClosings extends ListRecords
{
    protected static string $resource = PurchaseClosingResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos')
                ->badgeColor('gray'),
            Status::DRAFT->value => Tab::make('Rascunhos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::DRAFT->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::DRAFT->value)->count())
                ->badgeColor(Status::DRAFT->color()),
            Status::CLOSED->value => Tab::make('Fechados')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::CLOSED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::CLOSED->value)->count())
                ->badgeColor(Status::CLOSED->color()),
            Status::REOPENED->value => Tab::make('Reabertos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::REOPENED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::REOPENED->value)->count())
                ->badgeColor(Status::REOPENED->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Status::DRAFT->value;
    }
}
