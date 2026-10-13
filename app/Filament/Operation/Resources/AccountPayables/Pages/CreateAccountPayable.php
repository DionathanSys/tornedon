<?php

namespace App\Filament\Operation\Resources\AccountPayables\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\AccountPayables\AccountPayableResource;

class CreateAccountPayable extends \App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\CreateAccountPayable
{
    use HasOperationResourcePage;

    protected static string $resource = AccountPayableResource::class;

    protected string $view = 'filament.operation.resources.account-payables.pages.mobile-create';

    protected static ?string $title = 'Novo Contas à Pagar';

    public function getListUrl(): string
    {
        return AccountPayableResource::getUrl();
    }
}
