<?php

namespace App\Filament\Bpo\Resources\CashMovements\Pages;

use App\Filament\Bpo\Resources\CashMovementResource;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\EditCashMovement;

class EditMovement extends EditCashMovement
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-form';

    public function workbenchEyebrow(): string
    {
        return 'TESOURARIA · MOVIMENTO #'.$this->getRecord()->getKey();
    }

    public function workbenchDescription(): string
    {
        return 'Revise a movimentação manual, sua conta e sua classificação financeira.';
    }

    protected static string $resource = CashMovementResource::class;
}
