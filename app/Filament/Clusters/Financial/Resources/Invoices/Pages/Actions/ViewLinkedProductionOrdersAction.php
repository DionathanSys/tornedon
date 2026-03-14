<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedProductionOrdersAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedProductionOrders')
            ->label('OP.')
            ->icon(Heroicon::Cog6Tooth)
            ->color('gray')
            ->badge(fn (Invoice $record): int => $record->productionOrders()->count())
            ->badgeColor('primary')
            ->visible(fn (Invoice $record): bool => $record->productionOrders()->exists())
            ->modalHeading('Ordens de producao vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Invoice $record): View {
                return view('filament.actions.linked-production-order', [
                    'productionOrders' => $record->productionOrders()
                        ->orderBy('production_order_number')
                        ->get()
                        ->map(fn ($po) => (object) [
                            'url' => ProductionOrderResource::getUrl('edit', ['record' => $po]),
                            'number' => $po->production_order_number ?? $po->id,
                        ]),
                ]);
            });
    }
}
