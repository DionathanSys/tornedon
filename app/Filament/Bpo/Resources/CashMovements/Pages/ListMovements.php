<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMovements extends ListRecords
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';

    public function workbenchEyebrow(): string
    {
        return 'TESOURARIA · MOVIMENTAÇÕES';
    }

    public function workbenchDescription(): string
    {
        return 'Entradas e saídas reunidas por conta, com valores e classificação financeira em uma única visão.';
    }

    protected static string $resource = CashMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo movimento manual')];
    }
}
