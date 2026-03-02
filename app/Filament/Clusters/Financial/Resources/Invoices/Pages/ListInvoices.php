<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Fatura')
                ->icon(Heroicon::Plus),
        ];
    }
}
