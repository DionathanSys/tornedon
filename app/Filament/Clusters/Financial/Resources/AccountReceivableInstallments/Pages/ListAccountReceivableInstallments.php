<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages;

use App\Enum\AccountReceivable\Status;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\AccountReceivableInstallmentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountReceivableInstallments extends ListRecords
{
    protected static string $resource = AccountReceivableInstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            Status::PENDING->value => Tab::make('Pendente')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', Status::PENDING->value)
                    // ->orWhere('status', Status::PARTIALLY_RECEIVED->value)
                    )
                ->badge(static::getResource()::getEloquentQuery()
                    ->where('status', Status::PENDING->value)
                    // ->orWhere('status', Status::PARTIALLY_RECEIVED->value)
                    ->count())
                ->badgeColor(Status::PENDING->color()),
            Status::OVERDUE->value => Tab::make('Vencida')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', Status::OVERDUE->value))
                ->badge(static::getResource()::getEloquentQuery()
                    ->where('status', Status::OVERDUE->value)
                    ->count())
                ->badgeColor(Status::OVERDUE->color()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Status::PENDING->value;
    }
}
