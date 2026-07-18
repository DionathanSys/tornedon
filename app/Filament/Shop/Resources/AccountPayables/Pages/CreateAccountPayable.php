<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;

class CreateAccountPayable extends \App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\CreateAccountPayable
{
    protected static string $resource = AccountPayableResource::class;

    protected string $view = 'filament.shop.resources.account-payables.pages.mobile-create';

    protected static ?string $title = 'Novo Contas à Pagar';

    public function getListUrl(): string
    {
        return AccountPayableResource::getUrl();
    }
}
