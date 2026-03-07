<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class BulkInvoiceProductionOrderAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulkInvoiceProductionOrder')
            ->label('Faturar Selecionadas')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Ordens de Produção')
            ->modalDescription('Tem certeza que deseja faturar as ordens de produção selecionadas? Será criada uma única fatura agrupando todos os registros.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->schema([
                Checkbox::make('request_fiscal_document')
                    ->label('Solicitar documento fiscal agora')
                    ->helperText('Se desmarcado, a fatura será criada em aberto para posterior emissão do documento fiscal.')
                    ->default(false),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                // Validação: mesmo cliente
                $customerIds = $records->pluck('customer_id')->unique();

                if ($customerIds->count() > 1) {
                    Log::warning('BulkInvoiceProductionOrderAction: Registros de clientes distintos selecionados', [
                        'metodo'       => __METHOD__ . '@' . __LINE__,
                        'customer_ids' => $customerIds->all(),
                        'user_id'      => Auth::id(),
                    ]);

                    notify::error(
                        message: 'Todos os registros selecionados devem pertencer ao mesmo cliente para faturamento em lote.',
                    );

                    return;
                }

                // Validação: todas concluídas
                $notCompleted = $records->filter(fn (ProductionOrder $po) => $po->status !== Status::COMPLETED);

                if ($notCompleted->isNotEmpty()) {
                    Log::warning('BulkInvoiceProductionOrderAction: Registros com status inválido selecionados', [
                        'metodo'               => __METHOD__ . '@' . __LINE__,
                        'production_order_ids' => $notCompleted->pluck('id')->all(),
                        'user_id'              => Auth::id(),
                    ]);

                    notify::error(
                        message: 'Apenas ordens de produção com status "Concluída" podem ser faturadas.',
                    );

                    return;
                }

                $service = app(ProductionOrderService::class);
                $invoice = $service->invoice($records, Auth::id());

                if ($service->hasError() || ! $invoice) {
                    Log::error('BulkInvoiceProductionOrderAction: Erro ao faturar OPs em lote', [
                        'metodo'               => __METHOD__ . '@' . __LINE__,
                        'production_order_ids' => $records->pluck('id')->all(),
                        'error_code'           => $service->getErrorCode(),
                        'message'              => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('BulkInvoiceProductionOrderAction: OPs faturadas com sucesso', [
                    'metodo'               => __METHOD__ . '@' . __LINE__,
                    'production_order_ids' => $records->pluck('id')->all(),
                    'invoice_id'           => $invoice->id,
                ]);

                notify::success(
                    $records->count() . ' ordem(ns) de produção faturada(s) com sucesso. Fatura #' . $invoice->invoice_number
                );
            });
    }
}
