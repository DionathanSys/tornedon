<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Pages;

use App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages\ListCommitmentImports;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\CommitmentPaymentImportResource;

class ListCommitmentPaymentImports extends ListCommitmentImports
{
    protected static string $resource = CommitmentPaymentImportResource::class;
}
