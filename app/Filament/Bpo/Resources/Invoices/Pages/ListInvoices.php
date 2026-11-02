<?php

namespace App\Filament\Bpo\Resources\Invoices\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = InvoiceResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';
}
