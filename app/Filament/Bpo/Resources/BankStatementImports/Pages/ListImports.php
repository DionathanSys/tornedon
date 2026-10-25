<?php

namespace App\Filament\Bpo\Resources\BankStatementImports\Pages;

use App\Filament\Bpo\Resources\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Models\BankStatementImport;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListImports extends ListRecords
{
    protected static string $resource = BankStatementImportResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [ImportOfxAction::make()->visible(fn () => Gate::allows('create', BankStatementImport::class))
            ->before(fn () => Gate::authorize('create', BankStatementImport::class))];
    }
}
