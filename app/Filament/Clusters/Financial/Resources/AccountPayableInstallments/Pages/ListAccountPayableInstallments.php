<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages;

use App\Enum\AccountPayable\Status;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\AccountPayableInstallmentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountPayableInstallments extends ListRecords
{
    protected static string $resource = AccountPayableInstallmentResource::class;

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
                    ->orWhere('status', Status::PARTIALLY_PAID->value))
                ->badge(static::getResource()::getEloquentQuery()
                    ->where('status', Status::PENDING->value)
                    ->orWhere('status', Status::PARTIALLY_PAID->value)
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
