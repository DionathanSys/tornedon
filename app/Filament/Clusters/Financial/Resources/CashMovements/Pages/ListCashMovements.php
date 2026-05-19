<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCashMovements extends ListRecords
{
    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
            Action::make('view_ofx_imports')
                ->label('Importações OFX')
                ->icon('heroicon-o-document-duplicate')
                ->url(BankStatementImportResource::getUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }
}
