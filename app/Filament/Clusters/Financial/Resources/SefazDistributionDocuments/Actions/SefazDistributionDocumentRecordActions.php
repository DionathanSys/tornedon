<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;

final class SefazDistributionDocumentRecordActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            GenerateAccountPayableAction::make(),
            LinkAccountPayableAction::make(),
            LinkAccountPayableAction::repair(),
            Action::make('openAccountPayable')->label('Abrir conta a pagar')->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn (SefazDistributionDocument $record): bool => $record->accountPayable !== null
                    && AccountPayableResource::canView($record->accountPayable))
                ->url(fn (SefazDistributionDocument $record): ?string => $record->account_payable_id
                    ? AccountPayableResource::getUrl('view', ['record' => $record->account_payable_id]) : null),
            ImportDocumentAction::make(),
            DownloadXmlAction::make(),
            ViewXmlAction::make(),
            ViewDanfeAction::make(),
            RetryManifestationAction::make(),
            RetryRefreshAction::make(),
            RetryImportAction::make(),
            IgnoreDocumentAction::make(),
            ReactivateDocumentAction::make(),
            LinkSupplierAction::make(),
            CreateSupplierAction::make(),
            OpenFiscalDocumentAction::make(),
        ];
    }
}
