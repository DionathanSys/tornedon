<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class ViewLinkedProductionOrderAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedProductionOrder')
            ->label('Ver OP')
            ->icon(Heroicon::Cog6Tooth)
            ->color('info')
            ->badge(fn (Quote $record): ?string => $record->productionOrder ? $record->productionOrder->production_order_number : null)
            ->visible(fn (Quote $record): bool => $record->productionOrder()->exists())
            ->url(fn (Quote $record): string => ProductionOrderResource::getUrl('edit', [
                'record' => $record->productionOrder,
            ]))
            ->openUrlInNewTab();
    }
}
