<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;

class CreateAccountReceivable extends \App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable
{
    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.shop.resources.account-receivables.pages.mobile-create';

    protected static ?string $title = 'Novo Contas à Receber';

    public function getListUrl(): string
    {
        return AccountReceivableResource::getUrl();
    }
}
