<?php

namespace App\Filament\Bpo\Resources\PurchaseClosings\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\PurchaseClosingResource;

class CreatePurchaseClosing extends \App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\CreatePurchaseClosing
{
    use HasWorkbenchHeader;

    protected static string $resource = PurchaseClosingResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-form';
}
