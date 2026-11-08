<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Invoice\InvoiceAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceActionEligibilityService;
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
            ->label('Desfazer confirmação')
            ->icon(Heroicon::ArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Desfazer confirmação da fatura')
            ->modalDescription('A ação removerá os recebíveis e documentos fiscais locais elegíveis e retornará a fatura ao rascunho. O histórico da confirmação será preservado. Não é permitida após comunicação fiscal ou com cobranças externas vinculadas.')
            ->visible(fn (Invoice $record): bool => $record->confirmed && ! $record->canceled)
            ->disabled(fn (Invoice $record): bool => ! app(InvoiceActionEligibilityService::class)->evaluate($record, InvoiceAction::RETURN_TO_DRAFT)['allowed'])
            ->tooltip(fn (Invoice $record): string => implode(' ', array_column(app(InvoiceActionEligibilityService::class)->evaluate($record, InvoiceAction::RETURN_TO_DRAFT)['blockers'], 'message')))
            ->action(function (Invoice $record, EditInvoice $livewire): void {
                $service = app(InvoiceService::class);
                $result = $service->returnToPending($record, Auth::id());

                if (! $result || $service->hasError()) {
                    Log::error('ReturnInvoiceToPendingAction: erro ao retornar fatura para pendente', [
                        'metodo' => __METHOD__.'@'.__LINE__,
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
