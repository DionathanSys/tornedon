<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Models\Requisition;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class ViewInvoiceRequisitionAction
{
    public static function make(): Action
    {
        return Action::make('viewRequisitionInvoice')
            ->label('Abrir Fatura')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Requisition $record): bool => filled($record->invoice_id))
            ->url(fn (Requisition $record): string => InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]));
    }
}
