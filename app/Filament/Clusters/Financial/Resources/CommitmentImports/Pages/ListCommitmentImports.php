<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages;

use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ConfigureCommitmentsAction;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions\ImportCommitmentFilesAction;
use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCommitmentImports extends ListRecords
{
    protected static string $resource = CommitmentImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportCommitmentFilesAction::make(static::getResource()), ConfigureCommitmentsAction::make(),
            Action::make('commitments')->label('Conciliar empenhos')->url(CustomerCommitmentResource::getUrl('index')),
        ];
    }
}
