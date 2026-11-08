<?php

namespace App\Filament\Bpo\Resources\Invoices\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\GenerateFiscalDocumentAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\SendInvoiceEmailAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewInvoice extends ViewRecord
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = InvoiceResource::class;

    protected static string $layout = 'components.bpo.layout';

    public function getSubheading(): ?string
    {
        return "Fatura # {$this->record->invoice_number} - {$this->record->statusLabel()}";
    }

    protected function getHeaderActions(): array
    {
        return [$this->secureBpoAction(PreviewInvoicePdfAction::make()->modalSubmitAction(false), 'invoices.view'),
            $this->secureBpoAction(DownloadInvoicePdfAction::make(), 'invoices.view'),
            $this->secureBpoAction(SendInvoiceEmailAction::make(), 'invoices.update'),
            $this->secureBpoAction(GenerateFiscalDocumentAction::make(DocumentModel::NFE), 'fiscal_documents.create'),
            $this->secureBpoAction(GenerateFiscalDocumentAction::make(DocumentModel::NFSE), 'fiscal_documents.create')];
    }

    #[On('invoice-fiscal-state-changed')]
    public function refreshInvoiceState(): void
    {
        $this->getRecord()->refresh();
    }
}
