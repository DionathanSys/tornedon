<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\ServiceOrder\State;
use App\Jobs\ProcessQueuedNfseEmissionJob;
use App\Models\CommitmentBilling;
use App\Models\CommitmentBillingBatchItem;
use App\Models\CustomerCommitment;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\InvoiceService;
use App\Services\ServiceOrder\InvoiceServiceOrderWorkflow;
use Closure;
use Illuminate\Validation\ValidationException;

class BillCommitmentService
{
    public function process(CommitmentBillingBatchItem $item, int $userId, bool $emitNfse): void
    {
        $this->phase($item, $userId, function ($record, $billing, $order) use ($userId) {
            if ($billing->invoice_id) {
                $this->invoice($billing, $order);

                return;
            }
            app(CommitmentBillingData::class)->validate($billing, $billing->settings_snapshot ?? []);
            $workflow = app(InvoiceServiceOrderWorkflow::class);
            if (! $workflow->execute($order, $userId) || ! $workflow->invoice()) {
                $this->invalid($workflow->getMessageUser() ?: 'Falha ao faturar a OS.');
            }
            $billing->update(['invoice_id' => $workflow->invoice()->id, 'status' => BillingStatus::INVOICED, 'completed_step' => BillingStatus::INVOICED]);
            $this->invoice($billing, $order->fresh());
            $this->audit($record, 'commitment.invoiced', 'OS do empenho faturada.', $billing, $userId);
        });

        $this->phase($item, $userId, function ($record, $billing, $order) use ($userId) {
            $invoice = $this->invoice($billing, $order);
            if (! $invoice->confirmed) {
                app(CommitmentBillingData::class)->validate($billing, $billing->settings_snapshot ?? []);
                $service = app(InvoiceService::class);
                if ($service->confirm($invoice, $billing->settings_snapshot, $userId) === null || $service->hasError()) {
                    $this->invalid($service->getMessageUser() ?: 'Falha ao confirmar a fatura.');
                }
                $this->audit($record, 'commitment.invoice_confirmed', 'Fatura do empenho confirmada.', $billing, $userId);
            }
            $this->assertDocuments($billing, $invoice->fresh());
            $billing->update(['status' => BillingStatus::CONFIRMED, 'completed_step' => BillingStatus::CONFIRMED, 'last_error' => null]);
        });

        if ($emitNfse) {
            // The financial phases above have committed. Reuse the current emitter and fiscal queue.
            $this->phase($item, $userId, function ($record, $billing, $order) use ($userId) {
                $invoice = $this->invoice($billing, $order);
                $this->assertDocuments($billing, $invoice);
                $document = $invoice->fiscalDocuments()->where('document_type', DocumentModel::NFSE->value)->lockForUpdate()->sole();
                if (in_array($document->nfse_status, [NfeStatus::RPS_RECONCILIATION_PENDING, NfeStatus::CANCELED], true)) {
                    $this->invalid('Revise a situação fiscal da NFS-e antes de reenviar: '.$document->nfse_status->description());
                }
                if (! in_array($document->nfse_status, [NfeStatus::QUEUED, NfeStatus::IN_PROCESSING, NfeStatus::AUTHORIZED], true)) {
                    $service = app(NfseDocumentService::class);
                    if (! $service->emitir($document, $userId)) {
                        $this->invalid($service->getMessageUser() ?: 'Não foi possível encaminhar a NFS-e.');
                    }
                } elseif ($document->nfse_status === NfeStatus::QUEUED && filled($document->emission_group_key)) {
                    // Recover a failed queue dispatch using the existing group processor, without a new document or RPS.
                    dispatch(new ProcessQueuedNfseEmissionJob($document->emission_group_key))->afterCommit();
                }
                $billing->update(['status' => BillingStatus::EMISSION_QUEUED, 'completed_step' => BillingStatus::EMISSION_QUEUED, 'last_error' => null]);
                $this->audit($record, 'commitment.nfse_requested', 'Emissão solicitada pelo serviço fiscal existente.', $billing, $userId);
            });
        }
    }

    private function phase(CommitmentBillingBatchItem $item, int $userId, Closure $callback): void
    {
        $commitmentId = $item->billing->customer_commitment_id;
        app(CommitmentReviewContext::class)->transaction($item->company_id, $commitmentId, $userId, function (CustomerCommitment $record) use ($item, $callback) {
            $billing = $record->billing()->lockForUpdate()->firstOrFail();
            $currentItem = CommitmentBillingBatchItem::forCustomer($item->company_id, $item->customer_id)->lockForUpdate()->findOrFail($item->id);
            if ($billing->execution_token !== $item->execution_token || $currentItem->status !== 'processing') {
                $this->invalid('Esta tentativa foi substituída por uma retomada.');
            }
            if ($record->status->value !== 'approved') {
                $this->invalid('O vínculo do empenho deve estar aprovado.');
            }
            $order = ServiceOrder::query()->where('company_id', $record->company_id)->where('customer_id', $record->customer_id)->lockForUpdate()->findOrFail($billing->service_order_id);
            $order->equipment()->lockForUpdate()->first();
            $order->items()->lockForUpdate()->get();
            if ($order->requisition()->lockForUpdate()->exists()) {
                $this->invalid('Esta OS possui requisição. Use o faturamento normal; este fluxo atende somente serviços/NFS-e.');
            }
            if ($billing->invoice_id && ((int) $order->invoice_id !== (int) $billing->invoice_id || $order->status !== State::INVOICED)) {
                $this->invalid('A OS não está vinculada à fatura deste empenho.');
            }
            // Validate current patrimony and cents with the same matching engine, including retries of our own invoice.
            $candidate = clone $order;
            if ($billing->invoice_id) {
                $candidate->invoice_id = null;
                $candidate->status = State::CLOSED;
            }
            $engine = app(CommitmentMatchEngine::class);
            $match = $engine->candidate($record, $candidate);
            if (! $match['eligible'] || $engine->issues($record) !== []) {
                $this->invalid($match['eligible'] ? 'Revise as pendências da extração.' : $match['reason']);
            }
            $callback($record, $billing, $order);
            $currentItem->touch();
        });
    }

    private function invoice(CommitmentBilling $billing, ServiceOrder $order): Invoice
    {
        $invoice = Invoice::query()->where('company_id', $billing->company_id)->where('customer_id', $billing->customer_id)->lockForUpdate()->findOrFail($billing->invoice_id);
        if ($invoice->canceled || $invoice->serviceOrders()->count() !== 1 || ! $invoice->serviceOrders()->whereKey($order->id)->exists()
            || $invoice->requisitions()->exists() || CommitmentMatchValues::cents($invoice->net_value) !== (int) $billing->commitment->getRawOriginal('amount')) {
            $this->invalid('Fatura incompatível com o empenho. Revise o vínculo, os itens e o valor pela tela de faturas.');
        }

        return $invoice;
    }

    private function assertDocuments(CommitmentBilling $billing, Invoice $invoice): void
    {
        $documents = $invoice->fiscalDocuments()->get();
        $document = $documents->first();
        $snapshot = $billing->settings_snapshot;
        if (! $invoice->confirmed || $documents->count() !== 1 || $document->document_type !== DocumentModel::NFSE
            || (int) $document->company_id !== (int) $billing->company_id || (int) $document->customer_id !== (int) $billing->customer_id
            || ! $invoice->accountReceivables()->exists()) {
            $this->invalid('A confirmação deve gerar uma NFS-e e contas a receber para a mesma empresa e cliente. Revise a fatura.');
        }
        $fiscalItem = $document->items()->sole();
        if ($fiscalItem->description !== $snapshot['nfse_item_description'] || $fiscalItem->additional_information !== $snapshot['nfse_additional_information']
            || ($document->additional_taxpayer_information !== null && $document->additional_taxpayer_information !== $snapshot['nfse_additional_information'])) {
            $this->invalid('Os textos da NFS-e foram alterados fora deste fluxo. Revise o documento fiscal antes de emitir.');
        }
    }

    private function audit(CustomerCommitment $record, string $event, string $message, CommitmentBilling $billing, int $userId): void
    {
        app(AuditRecorder::class)->recordModelEvent($record, $event, $message, actorUserId: $userId, metadata: ['invoice_id' => $billing->invoice_id, 'batch_id' => $billing->active_batch_id]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }
}
