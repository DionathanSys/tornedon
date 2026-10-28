<?php

namespace App\Filament\Bpo\Resources\BankStatementImports\Pages;

use App\Filament\Bpo\Resources\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Models\BankStatementImport;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListImports extends ListRecords
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected string $view = 'filament.bpo.pages.workbench-list';

    public function workbenchEyebrow(): string
    {
        return 'TESOURARIA · CONCILIAÇÃO';
    }

    public function workbenchDescription(): string
    {
        return 'Importe extratos e acompanhe a conciliação entre os movimentos bancários e os registros do sistema.';
    }

    protected static string $resource = BankStatementImportResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [ImportOfxAction::make()->visible(fn () => Gate::allows('create', BankStatementImport::class))
            ->before(fn () => Gate::authorize('create', BankStatementImport::class))];
    }
}
