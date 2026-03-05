<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedServiceOrdersAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedServiceOrders')
            ->label('Ordens de Serviço')
            ->icon(Heroicon::WrenchScrewdriver)
            ->color('gray')
            ->badge(fn (Quote $record): int => $record->serviceOrders()->count())
            ->badgeColor('primary')
            ->visible(fn (Quote $record): bool => $record->serviceOrders()->exists())
            ->modalHeading('Ordens de Serviço vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Quote $record): View {
                return view('filament.actions.linked-service-orders', [
                    'serviceOrders' => $record->serviceOrders()
                        ->orderBy('number')
                        ->get()
                        ->map(fn ($so) => (object) [
                            'url'    => ServiceOrderResource::getUrl('edit', ['record' => $so]),
                            'number' => $so->number ?? $so->id,
                        ]),
                ]);
            });
    }
}
