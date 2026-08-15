<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewBankStatementImport extends ViewRecord
{
    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
            Action::make('reconcile')
                ->label('Conciliação')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->url(BankStatementImportResource::getUrl('reconcile', [
                    'record' => $this->getRecord(),
                    'tenant' => Filament::getTenant(),
                ]))
                ->visible(fn() => Auth::user()->is_admin),
            Action::make('back_to_list')
                ->label('Voltar')
                ->url(BankStatementImportResource::getUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }
}
