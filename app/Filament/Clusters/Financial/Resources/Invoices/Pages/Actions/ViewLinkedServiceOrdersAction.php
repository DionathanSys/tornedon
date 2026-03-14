<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedServiceOrdersAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedServiceOrders')
            ->label('OS.')
            ->icon(Heroicon::WrenchScrewdriver)
            ->color('gray')
            ->badge(fn (Invoice $record): int => $record->serviceOrders()->count())
            ->badgeColor('primary')
            ->visible(fn (Invoice $record): bool => $record->serviceOrders()->exists())
            ->modalHeading('Ordens de servico vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Invoice $record): View {
                return view('filament.actions.linked-service-orders', [
                    'serviceOrders' => $record->serviceOrders()
                        ->orderBy('number')
                        ->get()
                        ->map(fn ($so) => (object) [
                            'url' => ServiceOrderResource::getUrl('edit', ['record' => $so]),
                            'number' => $so->number ?? $so->id,
                        ]),
                ]);
            });
    }
}
