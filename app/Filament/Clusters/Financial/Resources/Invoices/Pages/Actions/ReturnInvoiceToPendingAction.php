<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ReturnInvoiceToPendingAction
{
    public static function make(): Action
    {
        return Action::make('returnInvoiceToPending')
            ->label('Voltar para pendente')
            ->icon(Heroicon::ArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Retornar fatura para pendente')
            ->modalDescription('A ação excluirá contas a receber não recebidas e documentos fiscais sem comunicação com a SEFAZ antes de voltar a fatura para pendente.')
            ->visible(fn (Invoice $record): bool => $record->confirmed && ! $record->canceled)
            ->action(function (Invoice $record, EditInvoice $livewire): void {
                $service = app(InvoiceService::class);
                $result = $service->returnToPending($record, Auth::id());

                if (! $result || $service->hasError()) {
                    Log::error('ReturnInvoiceToPendingAction: erro ao retornar fatura para pendente', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode(),
                    );

                    return;
                }

                notify::success('Fatura retornada para pendente com sucesso.');
                $livewire->refreshInvoiceState();
                $livewire->dispatch('invoice-account-receivables-refresh');
                $livewire->dispatch('invoice-fiscal-documents-refresh');
            });
    }
}
