<?php

namespace App\Filament\Bpo\Resources\Invoices\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\SendInvoiceEmailAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = InvoiceResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [$this->secureBpoAction(PreviewInvoicePdfAction::make()->modalSubmitAction(false), 'invoices.view'),
            $this->secureBpoAction(DownloadInvoicePdfAction::make(), 'invoices.view'),
            $this->secureBpoAction(SendInvoiceEmailAction::make(), 'invoices.update')];
    }
}
