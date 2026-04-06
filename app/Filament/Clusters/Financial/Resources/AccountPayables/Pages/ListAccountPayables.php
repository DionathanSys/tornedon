<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Pages;

use App\Enum\AccountPayable\Status;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListAccountPayables extends ListRecords
{
    protected static string $resource = AccountPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Conta a Pagar')
                ->icon(Heroicon::Plus),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            Status::PENDING->value => Tab::make('Pendente')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', Status::PENDING->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::PENDING->value)->count())
                ->badgeColor(Status::PENDING->color()),
            Status::PAID->value => Tab::make('Paga')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', Status::PAID->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::PAID->value)->count())
                ->badgeColor(Status::PAID->color()),
            Status::OVERDUE->value => Tab::make('Vencida')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', Status::OVERDUE->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::OVERDUE->value)->count())
                ->badgeColor(Status::OVERDUE->color()),
            Status::CANCELLED->value => Tab::make('Cancelada')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', Status::CANCELLED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', Status::CANCELLED->value)->count())
                ->badgeColor(Status::CANCELLED->color()),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'pendente';
    }
}
