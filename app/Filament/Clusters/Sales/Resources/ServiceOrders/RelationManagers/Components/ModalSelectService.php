<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ServiceTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\SchemaForm;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;

class ModalSelectService
{
    public static function make(?string $field = 'item.service_id'): AutoSubmitModalTableSelect
    {
        return AutoSubmitModalTableSelect::make($field)
            ->label('Serviço')
            ->saved(false)
            ->relationship('service', 'service_code')
            ->tableConfiguration(ServiceTable::class)
            ->selectAction(
                fn(Action $action) => $action
                    ->label('Selecionar')
                    ->modalHeading('Buscar Serviço')
                    ->modalSubmitActionLabel('Confirmar seleção')
                    ->slideOver(false)
                    ->modalWidth(Width::SevenExtraLarge)
            )
            ->afterStateUpdated(
                fn($state, Set $set, Get $get, $livewire) => SchemaForm::resolveItem($set, $get, Destination::ORDER_SERVICE, $state, $livewire)
            );
    }
}
