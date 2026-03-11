<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Models\ServiceOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class ViewInvoiceServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('viewServiceOrderInvoice')
            ->label('Fatura')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (ServiceOrder $record): bool => filled($record->invoice_id))
            ->url(fn (ServiceOrder $record): string => InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]));
    }
}
