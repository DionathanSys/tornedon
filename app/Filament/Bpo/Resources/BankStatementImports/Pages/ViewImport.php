<?php

namespace App\Filament\Bpo\Resources\BankStatementImports\Pages;

use App\Filament\Bpo\Resources\BankStatementImportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    protected static string $resource = BankStatementImportResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Voltar às importações')->url(static::getResource()::getUrl())];
    }
}
