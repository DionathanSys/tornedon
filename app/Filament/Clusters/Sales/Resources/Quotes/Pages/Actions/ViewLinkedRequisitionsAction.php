<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedRequisitionsAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedRequisitions')
            ->label('Requisições')
            ->icon(Heroicon::ClipboardDocumentList)
            ->color('gray')
            ->badge(fn (Quote $record): int => $record->requisitions()->count())
            ->badgeColor('primary')
            ->visible(fn (Quote $record): bool => $record->requisitions()->exists())
            ->modalHeading('Requisições vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Quote $record): View {
                return view('filament.actions.linked-requisitions', [
                    'requisitions' => $record->requisitions()
                        ->orderBy('number')
                        ->get()
                        ->map(fn ($req) => (object) [
                            'url'    => RequisitionResource::getUrl('edit', ['record' => $req]),
                            'number' => $req->number ?? $req->id,
                        ]),
                ]);
            });
    }
}
