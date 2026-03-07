<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class BulkInvoiceRequisitionAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulkInvoiceRequisition')
            ->label('Faturar Selecionadas')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Requisições')
            ->modalDescription('Tem certeza que deseja faturar as requisições selecionadas? Será criada uma única fatura agrupando todos os registros.')
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
                    Log::warning('BulkInvoiceRequisitionAction: Registros de clientes distintos selecionados', [
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
                $notClosed = $records->filter(fn (Requisition $req) => $req->status !== Status::CLOSED);

                if ($notClosed->isNotEmpty()) {
                    Log::warning('BulkInvoiceRequisitionAction: Registros com status inválido selecionados', [
                        'metodo'          => __METHOD__ . '@' . __LINE__,
                        'requisition_ids' => $notClosed->pluck('id')->all(),
                        'user_id'         => Auth::id(),
                    ]);

                    notify::error(
                        message: 'Apenas requisições com status "Encerrada" podem ser faturadas.',
                    );

                    return;
                }

                $service = app(RequisitionService::class);
                $invoice = $service->invoice($records, Auth::id());

                if ($service->hasError() || ! $invoice) {
                    Log::error('BulkInvoiceRequisitionAction: Erro ao faturar requisições em lote', [
                        'metodo'          => __METHOD__ . '@' . __LINE__,
                        'requisition_ids' => $records->pluck('id')->all(),
                        'error_code'      => $service->getErrorCode(),
                        'message'         => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('BulkInvoiceRequisitionAction: Requisições faturadas com sucesso', [
                    'metodo'          => __METHOD__ . '@' . __LINE__,
                    'requisition_ids' => $records->pluck('id')->all(),
                    'invoice_id'      => $invoice->id,
                ]);

                notify::success(
                    $records->count() . ' requisição(ões) faturada(s) com sucesso. Fatura #' . $invoice->invoice_number
                );
            });
    }
}
