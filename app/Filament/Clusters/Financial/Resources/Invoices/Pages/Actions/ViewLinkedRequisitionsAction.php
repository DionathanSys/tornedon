<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedRequisitionsAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedRequisitions')
            ->label('Requisicoes')
            ->icon(Heroicon::ClipboardDocumentList)
            ->color('gray')
            ->badge(fn (Invoice $record): int => $record->requisitions()->count())
            ->badgeColor('primary')
            ->visible(fn (Invoice $record): bool => $record->requisitions()->exists())
            ->modalHeading('Requisicoes vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Invoice $record): View {
                return view('filament.actions.linked-requisitions', [
                    'requisitions' => $record->requisitions()
                        ->orderBy('number')
                        ->get()
                        ->map(fn ($req) => (object) [
                            'url' => RequisitionResource::getUrl('edit', ['record' => $req]),
                            'number' => $req->number ?? $req->id,
                        ]),
                ]);
            });
    }
}
