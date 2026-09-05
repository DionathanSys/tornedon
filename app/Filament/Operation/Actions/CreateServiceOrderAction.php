<?php

namespace App\Filament\Operation\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction as SalesCreateServiceOrderAction;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

final class CreateServiceOrderAction
{
    public static function make(): CreateAction
    {
        return SalesCreateServiceOrderAction::make()
            ->name('createServiceOrder')
            ->label('Nova OS')
            ->hiddenLabel(false)
            ->successRedirectUrl(fn (Model $record): string => ServiceOrderDetail::getUrl(
                ['record' => $record],
                tenant: Filament::getTenant(),
            ));
    }
}
