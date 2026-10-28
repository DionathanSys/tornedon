<?php

namespace App\Filament\Bpo\Resources\BankStatementImports\Pages;

use App\Filament\Bpo\Resources\BankStatementImportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected string $view = 'filament.bpo.pages.bank-statement-import';

    public function workbenchEyebrow(): string
    {
        return 'CONCILIAÇÃO · IMPORTAÇÃO #'.$this->getRecord()->getKey();
    }

    public function workbenchDescription(): string
    {
        return 'Dados do extrato e movimentos bancários reunidos para conferir e conciliar os lançamentos.';
    }

    public function getTitle(): string
    {
        return 'Extrato bancário';
    }

    protected static string $resource = BankStatementImportResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Voltar às importações')->url(static::getResource()::getUrl())];
    }
}
