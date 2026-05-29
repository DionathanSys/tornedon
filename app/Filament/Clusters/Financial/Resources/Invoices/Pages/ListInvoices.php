<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Invoices\Widgets\InvoicesStatsOverview;
use Filament\Actions\CreateAction;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvoices extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InvoicesStatsOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
