<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ServiceTable;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ModalSelectService
{
    public static function make(?string $field = 'item.service_id'): ModalTableSelect
    {
        return ModalTableSelect::make($field)
            ->label('Serviço')
            ->saved(false)
            ->relationship('service', 'service_code')
            ->tableConfiguration(ServiceTable::class)
            ->selectAction(
                fn(Action $action) => $action
                    ->label('Selecionar')
                    ->modalHeading('Buscar Serviço')
                    ->modalSubmitActionLabel('Confirmar seleção'),
            )
            ->slideOver(false)
            ->afterStateUpdated(
                fn($state, Set $set, Get $get, $livewire) => SchemaForm::resolveItem($set, $get, Destination::ORDER_SERVICE, $state, $livewire)
            );
    }
}
