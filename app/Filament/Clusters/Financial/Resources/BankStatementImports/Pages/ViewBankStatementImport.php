<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewBankStatementImport extends ViewRecord
{
    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
            Action::make('back_to_list')
                ->label('Voltar')
                ->url(route('filament.admin.financial.resources.bank-statement-imports.index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }
}
