<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class BulkInvoiceServiceOrderAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulkInvoiceServiceOrder')
            ->label('Faturar Selecionadas')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Ordens de Serviço')
            ->modalDescription('Tem certeza que deseja faturar as ordens de serviço selecionadas? Será criada uma única fatura agrupando todos os registros.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                // Validação: mesmo cliente
                $customerIds = $records->pluck('customer_id')->unique();

                if ($customerIds->count() > 1) {
                    Log::warning('BulkInvoiceServiceOrderAction: Registros de clientes distintos selecionados', [
                        'metodo'       => __METHOD__ . '@' . __LINE__,
                        'customer_ids' => $customerIds->all(),
                        'user_id'      => Auth::id(),
                    ]);

                    notify::error(
                        message: 'Todos os registros selecionados devem pertencer ao mesmo cliente para faturamento em lote.',
                    );

                    return;
                }

                // Validação: todas encerradas
                $notClosed = $records->filter(fn (ServiceOrder $so) => $so->status !== State::CLOSED);

                if ($notClosed->isNotEmpty()) {
                    Log::warning('BulkInvoiceServiceOrderAction: Registros com status inválido selecionados', [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_ids' => $notClosed->pluck('id')->all(),
                        'user_id'           => Auth::id(),
                    ]);

                    notify::error(
                        message: 'Apenas ordens de serviço com status "Encerrada" podem ser faturadas.',
                    );

                    return;
                }

                $service = app(ServiceOrderService::class);
                $invoice = $service->invoice($records, Auth::id());

                if ($service->hasError() || ! $invoice) {
                    Log::error('BulkInvoiceServiceOrderAction: Erro ao faturar OS em lote', [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_ids' => $records->pluck('id')->all(),
                        'error_code'        => $service->getErrorCode(),
                        'message'           => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('BulkInvoiceServiceOrderAction: OS(s) faturada(s) com sucesso', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_ids' => $records->pluck('id')->all(),
                    'invoice_id'        => $invoice->id,
                ]);

                notify::success(
                    $records->count() . ' ordem(ns) de serviço faturada(s) com sucesso. Fatura #' . $invoice->invoice_number
                );
            })
            ->successRedirectUrl(fn($records) => InvoiceResource::getUrl('edit', ['record' => $records->first()->invoice_id]));
    }
}
