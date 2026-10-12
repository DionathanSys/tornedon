<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\DuplicateServiceOrderWorkflow;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class DuplicateServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('duplicateServiceOrder')
            ->label('Duplicar')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Duplicar Ordem de Serviço')
            ->modalDescription('Será criada uma nova OS com os mesmos dados e itens desta ordem.')
            ->modalSubmitActionLabel('Duplicar')
            ->action(function (ServiceOrder $record, $livewire): void {
                $workflow = app(DuplicateServiceOrderWorkflow::class);
                $duplicated = $workflow->execute($record, (int) Auth::id());

                if (! $duplicated) {
                    notify::error(message: $workflow->getMessageUser(), errorCode: $workflow->getErrorCode());

                    return;
                }

                notify::success($workflow->getMessage());
                redirect(static::resolveResource($livewire)::getUrl('edit', ['record' => $duplicated]));
            });
    }

    private static function resolveResource(mixed $livewire): string
    {
        return is_object($livewire) && method_exists($livewire, 'getResource') ? $livewire->getResource() : ServiceOrderResource::class;
    }
}
