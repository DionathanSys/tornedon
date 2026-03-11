<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class ViewLinkedFiscalDocumentsAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedFiscalDocuments')
            ->label('Notas Fiscais')
            ->icon(Heroicon::DocumentText)
            ->color('gray')
            ->badge(fn (Invoice $record): int => $record->fiscalDocuments()->count())
            ->badgeColor('primary')
            ->visible(fn (Invoice $record): bool => $record->fiscalDocuments()->exists())
            ->modalHeading('Notas fiscais vinculadas')
            ->modalWidth(Width::ExtraSmall)
            ->modalAlignCenter()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Invoice $record): View {
                return view('filament.actions.linked-fiscal-documents', [
                    'fiscalDocuments' => $record->fiscalDocuments()
                        ->orderByDesc('id')
                        ->get()
                        ->map(fn ($doc) => (object) [
                            'url' => FiscalDocumentResource::getUrl('edit', ['record' => $doc]),
                            'number' => $doc->number ?? $doc->id,
                        ]),
                ]);
            });
    }
}
