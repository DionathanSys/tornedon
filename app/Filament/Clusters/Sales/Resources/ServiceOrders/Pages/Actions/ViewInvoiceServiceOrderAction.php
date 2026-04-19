<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

final class ViewInvoiceServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('viewServiceOrderInvoice')
            ->label('Acessar Fatura')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (ServiceOrder $record): bool => filled($record->invoice_id) && filled(static::resolveInvoiceResource()))
            ->url(fn (ServiceOrder $record): string => static::resolveInvoiceResource()::getUrl('edit', ['record' => $record->invoice_id]));
    }

    private static function resolveInvoiceResource(): ?string
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel) {
            return InvoiceResource::class;
        }

        return $panel->getModelResource(Invoice::class);
    }
}
