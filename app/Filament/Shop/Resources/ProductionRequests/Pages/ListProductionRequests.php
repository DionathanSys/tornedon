<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Filament\Shop\Resources\ProductionRequests\Widgets\ProductionRequestOverview;
use Carbon\CarbonImmutable;
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

    protected function getHeaderWidgets(): array
    {
        return [
            ProductionRequestOverview::class,
        ];
    }

    public function getTabs(): array
    {
        $today = CarbonImmutable::today();

        return [
            'late' => Tab::make('Atras.')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', Status::OPEN->value)
                    ->whereDate('order_date', '<', $today))
                ->badge($this->countTab(fn (Builder $query): Builder => $query
                    ->where('status', Status::OPEN->value)
                    ->whereDate('order_date', '<', $today)))
                ->badgeColor('danger'),
            'today' => Tab::make('Hoje')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', Status::OPEN->value)
                    ->whereDate('order_date', $today))
                ->badge($this->countTab(fn (Builder $query): Builder => $query
                    ->where('status', Status::OPEN->value)
                    ->whereDate('order_date', $today)))
                ->badgeColor('info'),
            Status::OPEN->value => Tab::make('Abertos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::OPEN->value))
                ->badge($this->countTab(fn (Builder $query): Builder => $query->where('status', Status::OPEN->value)))
                ->badgeColor(Status::OPEN->color()),
            Status::DELIVERED->value => Tab::make('Entreg.')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::DELIVERED->value))
                ->badge($this->countTab(fn (Builder $query): Builder => $query->where('status', Status::DELIVERED->value)))
                ->badgeColor(Status::DELIVERED->color()),
            Status::CANCELLED->value => Tab::make('Canc.')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Status::CANCELLED->value))
                ->badge($this->countTab(fn (Builder $query): Builder => $query->where('status', Status::CANCELLED->value)))
                ->badgeColor(Status::CANCELLED->color()),
            'all' => Tab::make('Todos')
                ->badge($this->countTab())
                ->badgeColor('gray'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'today';
    }

    private function countTab(?callable $scope = null): int
    {
        $query = static::getResource()::getEloquentQuery();

        if ($scope !== null) {
            $query = $scope($query);
        }

        return $query->count();
    }
}
