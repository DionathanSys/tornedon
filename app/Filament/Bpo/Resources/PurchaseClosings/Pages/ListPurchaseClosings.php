<?php

namespace App\Filament\Bpo\Resources\PurchaseClosings\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\PurchaseClosingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseClosings extends ListRecords
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = PurchaseClosingResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo fechamento')];
    }
}
