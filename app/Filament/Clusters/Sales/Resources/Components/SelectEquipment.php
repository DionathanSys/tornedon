<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Services\Equipment\EquipmentService;
use App\Services\Partner\PartnerService;
use App\Services\Product\ProductSalePriceService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SelectEquipment
{
    public static function make(string $field, ?string $type = 'equipment_id'): Select
    {
        return Select::make($field)
            ->label('Equipamento')
            ->searchable()
            ->required()
            ->columnSpan(['md' => 2, 'lg' => 8])
            ->getSearchResultsUsing(
                fn(string $search): array => (new EquipmentService())
                    ->searchForSelect($search, Filament::getTenant()->id)
            )
            ->getOptionLabelUsing(
                fn($value): ?string => (new EquipmentService())
                    ->getLabelForSelect((int) $value)
            )
        ;
    }
}
