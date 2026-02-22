<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Services\Partner\PartnerService;
use App\Services\Product\ProductSalePriceService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SelectPartner
{
    public static function make(string $field, ?string $type = 'customer'): Select
    {
        return Select::make($field)
            ->label('Parceiro')
            ->searchable()
            ->required()
            ->columnSpan(['md' => 2, 'lg' => 8])
            ->getSearchResultsUsing(
                fn(string $search): array => (new PartnerService())
                    ->searchForSelect($search, Filament::getTenant()->id, $type)
            )
            ->getOptionLabelUsing(
                fn($value): ?string => (new PartnerService())
                    ->getLabelForSelect((int) $value)
            )
        ;
    }
}
