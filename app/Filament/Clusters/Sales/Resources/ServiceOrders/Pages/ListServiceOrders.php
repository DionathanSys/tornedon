<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListServiceOrders extends ListRecords
{
    protected static string $resource = ServiceOrderResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            State::OPEN->value => Tab::make('Pendente')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', State::OPEN->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', State::OPEN->value)->count())
                ->badgeColor(State::OPEN->color()),
            State::CLOSED->value => Tab::make('Encerrada')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', State::CLOSED->value))
                ->badge(static::getResource()::getEloquentQuery()->where('status', State::CLOSED->value)->count())
                ->badgeColor(State::CLOSED->color()),
            State::INVOICED->value => Tab::make('Faturada')
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', State::INVOICED->value))
                ->badge(null),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'aberta';
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('new-partner')
                    ->label('Novo Parceiro')
                    ->icon(Heroicon::Plus)
                    ->size(Size::ExtraSmall)
                    ->url(CompanyPartnerResource::getUrl('create'), true)
            ])
        ];
    }
}
