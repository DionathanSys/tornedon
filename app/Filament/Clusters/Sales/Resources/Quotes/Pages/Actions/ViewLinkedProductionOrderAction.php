<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedProductionOrderAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedProductionOrder')
            ->label('Ordem de Produção')
            ->icon(Heroicon::Cog6Tooth)
            ->color('gray')
            ->badge(fn (Quote $record): int => $record->productionOrder()->count())
            ->badgeColor('primary')
            ->visible(fn (Quote $record): bool => $record->productionOrder()->exists())
            ->modalHeading('Ordem de Produção vinculada')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Quote $record): View {
                return view('filament.actions.linked-production-order', [
                    'productionOrders' => $record->productionOrder()->orderBy('production_order_number')
                        ->get()
                        ->map(fn ($po) => (object) [
                            'url'    => ProductionOrderResource::getUrl('edit', ['record' => $po]),
                            'number' => $po->production_order_number ?? $po->id,
                        ]),
                ]);
            });
    }
}
