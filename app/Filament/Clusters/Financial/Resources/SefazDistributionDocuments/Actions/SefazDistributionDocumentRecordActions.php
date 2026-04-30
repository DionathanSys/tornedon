<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use Filament\Actions\Action;

final class SefazDistributionDocumentRecordActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            ViewTimelineAction::make(),
            ImportDocumentAction::make(),
            DownloadXmlAction::make(),
            ViewXmlAction::make(),
            RetryManifestationAction::make(),
            RetryRefreshAction::make(),
            RetryImportAction::make(),
            IgnoreDocumentAction::make(),
            ReactivateDocumentAction::make(),
            LinkSupplierAction::make(),
            CreateSupplierAction::make(),
            LinkItemsAction::make(),
            OpenFiscalDocumentAction::make(),
        ];
    }
}
