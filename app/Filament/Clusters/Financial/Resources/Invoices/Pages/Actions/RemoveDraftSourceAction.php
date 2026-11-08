<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Invoice\InvoiceAction;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Invoice\InvoiceActionEligibilityService;
use App\Services\Invoice\InvoiceDraftCompositionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class RemoveDraftSourceAction
{
    public static function make(): Action
    {
        return Action::make('removeFromDraft')
            ->label('Remover da fatura')->color('danger')->icon('heroicon-o-link-slash')
            ->requiresConfirmation()
            ->modalDescription('A ordem retornará para Encerrada e ficará disponível para faturar novamente. Requisições desta fatura vinculadas à OS também serão liberadas. O estoque não será movimentado.')
            ->visible(fn ($livewire): bool => app(InvoiceActionEligibilityService::class)->evaluate($livewire->getOwnerRecord(), InvoiceAction::EDIT_COMPOSITION)['allowed'])
            ->action(function (Requisition|ServiceOrder $record, $livewire): void {
                $invoice = $livewire->getOwnerRecord();
                abort_unless((int) $invoice->company_id === (int) Filament::getTenant()->id, 403);
                Gate::authorize('update', $invoice);
                Gate::authorize('update', $record);
                app(InvoiceDraftCompositionService::class)->remove($invoice, $record, Filament::auth()->id());
                Notification::make()->success()->title('Ordem removida e liberada para faturamento')->send();
                $livewire->dispatch('invoice-fiscal-documents-refresh');
            });
    }
}
