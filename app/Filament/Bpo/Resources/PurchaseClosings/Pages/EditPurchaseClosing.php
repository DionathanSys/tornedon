<?php

namespace App\Filament\Bpo\Resources\PurchaseClosings\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\PurchaseClosingResource;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions\GeneratePurchaseClosingAccountPayableAction;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions\ReopenPurchaseClosingAction;
use Filament\Actions\Action;

class EditPurchaseClosing extends \App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\EditPurchaseClosing
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = PurchaseClosingResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-form';

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Voltar aos fechamentos')->url(static::getResource()::getUrl()),
            $this->secureBpoAction(GeneratePurchaseClosingAccountPayableAction::make(), 'purchase_closings.update')
                ->authorize(fn ($record) => $this->canUseBpoAction('purchase_closings.update', $record) && $this->canUseBpoAction('account_payables.create') && $this->canUseBpoAction('account_payables.update'))
                ->before(function ($record): void {
                    $this->authorizeBpoAction('purchase_closings.update', $record);
                    $this->authorizeBpoAction('account_payables.create');
                    $this->authorizeBpoAction('account_payables.update');
                }),
            $this->secureBpoAction(ReopenPurchaseClosingAction::make(), 'purchase_closings.update'),
        ];
    }
}
