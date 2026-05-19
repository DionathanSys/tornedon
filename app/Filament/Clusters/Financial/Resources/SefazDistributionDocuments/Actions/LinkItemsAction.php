<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

class LinkItemsAction
{
    public static function make(): Action
    {
        return Action::make('linkItems')
            ->label('Vincular itens a produtos')
            ->icon('heroicon-o-link')
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->visible(fn(SefazDistributionDocument $record): bool => $record->full_xml_available && ! empty($record->items_json))
            ->modalContent(fn(SefazDistributionDocument $record): View => view(
                'filament.financial.sefaz-distribution-documents.actions.link-items-table',
                ['document' => $record],
            ));
    }
}
