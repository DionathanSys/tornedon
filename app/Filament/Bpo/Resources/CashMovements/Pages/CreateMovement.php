<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\CreateCashMovement;

class CreateMovement extends CreateCashMovement
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-form';

    public function workbenchEyebrow(): string
    {
        return 'TESOURARIA · NOVO MOVIMENTO';
    }

    public function workbenchDescription(): string
    {
        return 'Registre uma entrada ou saída manual e defina a conta e a classificação da movimentação.';
    }

    protected static string $resource = CashMovementResource::class;
}
