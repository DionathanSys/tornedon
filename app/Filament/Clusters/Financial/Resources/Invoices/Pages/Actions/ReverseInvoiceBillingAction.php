<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Jobs\ProcessInvoiceReversalJob;
use App\Models\Invoice;
use App\Services\Invoice\ReverseServiceInvoiceWorkflow;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class ReverseInvoiceBillingAction
{
    public static function make(): Action
    {
        return Action::make('reverseInvoiceBilling')->label('Reverter Faturamento')->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->visible(fn (Invoice $record): bool => $record->confirmed && app(ReverseServiceInvoiceWorkflow::class)->canManage(Filament::auth()->user(), $record))
            ->modalHeading('Reverter integralmente o faturamento de serviços')
            ->modalDescription('Notas e cobranças geradas pela fatura serão tratadas antes de liberar as OS para Encerrada. Títulos preexistentes serão preservados. A fatura anterior ficará no histórico e nenhuma nova fatura será criada. Nesta etapa não são aceitas faturas com produtos ou produção.')
            ->schema([
                Hidden::make('request_id')->default(fn () => (string) Str::uuid())->required(),
                Textarea::make('current_state')->label('Situação / impedimentos')->disabled()->dehydrated(false)->rows(4)
                    ->default(function (Invoice $record): string {
                        $operation = $record->operations()->where('active_slot', 1)->first();

                        return $operation ? $operation->status->description().': '.$operation->last_message : (implode("\n", app(ReverseServiceInvoiceWorkflow::class)->blockers($record)) ?: 'Fatura elegível. As ordens serão liberadas somente ao concluir os cancelamentos.');
                    }),
                Textarea::make('justification')->label('Justificativa')->required()->minLength(15)->maxLength(255)->rows(4)
                    ->default(fn (Invoice $record) => $record->operations()->where('active_slot', 1)->value('justification')),
            ])
            ->action(function (Invoice $record, array $data, $livewire): void {
                abort_unless((int) $record->company_id === (int) Filament::getTenant()?->id, 403);
                $operation = app(ReverseServiceInvoiceWorkflow::class)->request($record, Filament::auth()->user(), $data['justification'], $data['request_id']);
                ProcessInvoiceReversalJob::dispatch($operation->id)->afterCommit();
                $operation->refresh();
                Notification::make()->success()->title('Reversão de faturamento registrada')->body($operation->last_message)->send();
                if (method_exists($livewire, 'refreshInvoiceState')) {
                    $livewire->refreshInvoiceState();
                }
                $livewire->dispatch('invoice-fiscal-documents-refresh');
            });
    }
}
