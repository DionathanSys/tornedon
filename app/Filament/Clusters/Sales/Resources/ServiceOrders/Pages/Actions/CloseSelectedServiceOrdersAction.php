<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CloseSelectedServiceOrdersAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('closeSelectedServiceOrders')
            ->label('Encerrar Selecionadas')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Encerrar Ordens de Serviço')
            ->modalDescription('Tem certeza que deseja encerrar as ordens de serviço selecionadas? Apenas registros com status "Aberta" serão processados.')
            ->modalSubmitActionLabel('Sim, encerrar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $openRecords = $records->filter(fn (ServiceOrder $record) => $record->status === State::OPEN);
                $ignoredCount = $records->count() - $openRecords->count();

                if ($openRecords->isEmpty()) {
                    notify::warning('Nenhuma ordem de serviço aberta foi selecionada para encerramento.');

                    return;
                }

                $workflow = app(CloseServiceOrderWorkflow::class);
                $closedCount = 0;

                foreach ($openRecords as $record) {
                    $result = $workflow->execute(
                        serviceOrder: $record,
                        userId: Auth::id(),
                        sendEmail: false,
                        shouldInvoiceAfterClose: false,
                    );

                    if (! $result) {
                        Log::error('CloseSelectedServiceOrdersAction: erro ao encerrar OS em lote', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $record->id,
                            'error_code' => $workflow->getErrorCode(),
                            'message' => $workflow->getMessage(),
                        ]);

                        notify::error(
                            message: $workflow->getMessageUser(),
                            errorCode: $workflow->getErrorCode(),
                        );

                        return;
                    }

                    $closedCount++;
                }

                Log::info('CloseSelectedServiceOrdersAction: OS(s) encerrada(s) com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_ids' => $openRecords->pluck('id')->all(),
                    'user_id' => Auth::id(),
                    'ignored_count' => $ignoredCount,
                ]);

                notify::success("{$closedCount} ordem(ns) de serviço encerrada(s) com sucesso. {$ignoredCount} registro(s) foram ignorado(s) por nao estarem com status aberta.");
            });
    }
}
