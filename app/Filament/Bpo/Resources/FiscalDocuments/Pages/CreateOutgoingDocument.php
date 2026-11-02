<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\OutgoingFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Models\CompanyPartner;

class CreateOutgoingDocument extends CreateFiscalDocument
{
    use HasWorkbenchHeader;

    protected static string $resource = OutgoingFiscalDocumentResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-record';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        abort_unless(CompanyPartner::where('company_id', $data['company_id'])->where('partner_id', $data['customer_id'])->where('is_active', true)->exists(), 403);

        return $data;
    }
}
