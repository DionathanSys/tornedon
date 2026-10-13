<?php

namespace App\Filament\Operation\Resources\AccountReceivables\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\AccountReceivables\AccountReceivableResource;

class CreateAccountReceivable extends \App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable
{
    use HasOperationResourcePage;

    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.operation.resources.account-receivables.pages.mobile-create';

    protected static ?string $title = 'Novo Contas à Receber';

    public function getListUrl(): string
    {
        return AccountReceivableResource::getUrl();
    }
}
