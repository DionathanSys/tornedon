<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use Filament\Resources\Pages\ListRecords;

class ListBankStatementImports extends ListRecords
{
    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
        ];
    }
}
