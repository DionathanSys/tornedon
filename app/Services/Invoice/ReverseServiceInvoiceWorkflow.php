<?php

namespace App\Services\Invoice;

use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Enum\FiscalDocument\Status as FiscalStatus;
use App\Enum\Invoice\CancellationReason;
use App\Enum\Invoice\OperationStatus;
use App\Enum\Invoice\OperationType;
use App\Enum\Invoice\Status;
use App\Enum\ServiceOrder\State;
use App\Models\BankSlip;
use App\Models\Invoice;
use App\Models\InvoiceOperation;
use App\Models\PixCharge;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\Banking\BankChargeLifecycleService;
use App\Services\FiscalDocument\NfseDocumentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReverseServiceInvoiceWorkflow
{
    public function canManage(?User $user, Invoice $invoice): bool
    {
        $user = $user?->fresh();
        if (! $user || ! $user->canAccessTenant($invoice->company)) {
            return false;
        }
        $access = app(CompanyAuthorization::class);
        $membership = $access->membership($user, $invoice->company);

        return $membership && (($membership->access_mode === 'legacy' && $membership->role === 'owner') || in_array('company', $access->scopes($user, $invoice->company, 'team.manage'), true));
    }

    public function blockers(Invoice $invoice): array
    {
        $errors = [];
        if ($invoice->status !== Status::CONFIRMED || $invoice->canceled) {
            $errors[] = 'Somente uma fatura confirmada pode ter seu faturamento revertido.';
        }
        $confirmation = $invoice->confirmedComposition();
        if (! $confirmation) {
            $errors[] = 'A fatura não possui composição histórica validada. Revise o registro legado antes de reverter.';
        }
        if ($invoice->requisitions()->exists() || $invoice->productionOrders()->exists()
            || ($confirmation && $confirmation->sources()->where('source_type', '!=', 'service_order')->exists())
            || $invoice->fiscalDocuments()->where('document_type', '!=', 'nfse')->exists()) {
            $errors[] = 'Esta etapa atende apenas serviços sem requisições ou produção. Produtos e estoque serão tratados na próxima etapa.';
        }
        $orders = $invoice->serviceOrders()->where('company_id', $invoice->company_id)->get();
        if ($orders->isEmpty() || $orders->contains(fn ($order) => (int) $order->customer_id !== (int) $invoice->customer_id || $order->status !== State::INVOICED)) {
            $errors[] = 'As ordens vinculadas não estão disponíveis para reversão integral.';
        }
        if ($confirmation && $orders->pluck('id')->sort()->values()->all() !== $confirmation->sources()->where('source_type', 'service_order')->pluck('source_id')->sort()->values()->all()) {
            $errors[] = 'A composição atual difere da confirmação histórica.';
        }
        $links = $invoice->receivableLinks()->where('active_slot', 1)->get();
        if ($links->contains(fn ($link) => (int) $link->company_id !== (int) $invoice->company_id || ! in_array($link->origin, ['generated', 'linked_existing'], true))) {
            $errors[] = 'Origem ou empresa de um vínculo financeiro divergente.';
        }
        $titles = $invoice->accountReceivables()->get();
        if ($titles->pluck('id')->sort()->values()->all() !== $links->pluck('account_receivable_id')->sort()->values()->all()) {
            $errors[] = 'Existem títulos sem origem financeira rastreada ou vínculos divergentes. Não é seguro inferir se eram preexistentes.';
        }
        foreach ($titles as $title) {
            if ((int) $title->company_id !== (int) $invoice->company_id || (int) $title->customer_id !== (int) $invoice->customer_id) {
                $errors[] = 'Título de outra empresa ou cliente associado à fatura.';
            }
            $errors = [...$errors, ...app(InvoiceReceiptReversalService::class)->blockers($title)];
        }

        return array_values(array_unique($errors));
    }

    public function request(Invoice $invoice, User $user, string $justification, string $requestId): InvoiceOperation
    {
        abort_unless($this->canManage($user, $invoice), 403);
        Validator::make(['justification' => $justification, 'request_id' => $requestId], ['justification' => 'required|string|min:15|max:255', 'request_id' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($invoice, $user, $justification, $requestId): InvoiceOperation {
            $invoice = Invoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($this->canManage($user, $invoice), 403);
            $previous = InvoiceOperation::where('request_id', $requestId)->first();
            if ($previous) {
                abort_unless((int) $previous->invoice_id === (int) $invoice->id && $previous->type === OperationType::REVERSE_BILLING, 403);
                if ($previous->status->isActive()) {
                    $previous->update(['last_run_by' => $user->id, 'status' => OperationStatus::REQUESTED]);
                }

                return $previous;
            }
            $active = $invoice->operations()->where('active_slot', 1)->first();
            if ($active) {
                if ($active->type !== OperationType::REVERSE_BILLING) {
                    throw ValidationException::withMessages(['invoice' => 'Existe outra operação em andamento.']);
                }
                $active->update(['last_run_by' => $user->id, 'status' => OperationStatus::REQUESTED]);

                return $active;
            }
            $errors = $this->blockers($invoice);
            if ($invoice->fiscalDocuments()->get()->contains(fn ($document) => $document->isNfseQueued() || $document->isNfseInProcessing())) {
                $errors[] = 'Aguarde a conclusão da emissão fiscal antes de iniciar a reversão.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['invoice' => $errors]);
            }
            $operation = $invoice->operations()->create(['company_id' => $invoice->company_id, 'type' => OperationType::REVERSE_BILLING, 'status' => OperationStatus::REQUESTED, 'justification' => $justification, 'request_id' => $requestId, 'requested_by' => $user->id, 'last_run_by' => $user->id, 'steps' => [], 'last_message' => 'Reversão integral solicitada. As ordens permanecem vinculadas até a conclusão.']);
            // Prevent late automatic issuance/receipt while cancellation is coordinated.
            $invoice->update(['auto_bank_slip_issuance' => false, 'auto_pix_charge_issuance' => false]);
            foreach ($invoice->receivableLinks()->where('active_slot', 1)->where('origin', 'generated')->get() as $link) {
                $link->accountReceivable->update(['auto_register_receipt_on_due_date' => false]);
                foreach ($link->accountReceivable->installments as $installment) {
                    $installment->update(['auto_bank_slip_issuance' => false, 'auto_pix_charge_issuance' => false]);
                }
            }
            app(AuditRecorder::class)->recordModelEvent($invoice, 'invoice.reversal_requested', 'Reverter Faturamento de serviços solicitado', actorUserId: $user->id, metadata: ['operation_id' => $operation->id, 'justification' => $justification]);

            return $operation;
        });
    }

    public function process(InvoiceOperation $operation): void
    {
        Cache::lock('invoice-reversal:'.$operation->id, 300)->get(function () use ($operation): void {
            $operation->refresh();
            if ($operation->type !== OperationType::REVERSE_BILLING || ! $operation->status->isActive()) {
                return;
            }
            try {
                $invoice = $operation->invoice()->firstOrFail();
                $user = User::find($operation->last_run_by ?? $operation->requested_by);
                if (! $this->canManage($user, $invoice)) {
                    $this->pause($operation, 'O administrador responsável perdeu acesso à empresa. Outro administrador deve retomar a operação.');

                    return;
                }
                $errors = $this->blockers($invoice);
                if ($errors !== []) {
                    $this->pause($operation, implode(' ', $errors));

                    return;
                }
                $operation->update(['status' => OperationStatus::RUNNING, 'last_message' => 'Processando cancelamentos e verificando recebimentos.']);
                foreach ($invoice->fiscalDocuments()->where('company_id', $invoice->company_id)->orderBy('id')->get() as $document) {
                    $this->assertAdministrator($user, $invoice);
                    if ($errors = $this->blockers($invoice->fresh())) {
                        $this->pause($operation, implode(' ', $errors));

                        return;
                    }
                    $key = 'nfse:'.$document->id;
                    if ($document->isFiscalCancellationConfirmed() || ($document->status === FiscalStatus::CANCELLED && ! $document->isNfseSent())) {
                        $this->step($operation, $key, 'completed', 'Nota já cancelada/encerrada.');

                        continue;
                    }
                    if ($document->isNfseQueued() || $document->isNfseInProcessing()) {
                        $this->pause($operation, 'Aguardando resultado da emissão da NFS-e #'.$document->id, true);

                        return;
                    }
                    if (! $document->isNfseAuthorized() && (! $document->isNfseSent() || ($document->isNfseRejected() && ! $document->authorized_at))) {
                        $document->update(['status' => FiscalStatus::CANCELLED, 'canceled' => true, 'confirmed' => false, 'pending' => false, 'canceled_at' => now(), 'canceled_by' => $user->id]);
                        $this->step($operation, $key, 'completed', 'Documento fiscal não autorizado encerrado localmente.');

                        continue;
                    }
                    $service = app(NfseDocumentService::class);
                    $state = data_get($operation->steps, $key.'.state');
                    if ($state === 'started' || $document->fiscalIssues()->where('active_slot', 1)->where('status', 'awaiting_confirmation')->exists()) {
                        $consulted = $service->consultar($document, $user->id);
                        $document->refresh();
                        if ($document->isFiscalCancellationConfirmed()) {
                            $this->step($operation, $key, 'completed', 'Cancelamento fiscal confirmado em consulta.');

                            continue;
                        }
                        if ($consulted && $document->isNfseAuthorized()) {
                            $this->step($operation, $key, 'failed', 'Consulta confirmou nota ainda autorizada. Uma retomada explícita poderá solicitar novo cancelamento.');
                        }
                        $this->pause($operation, 'Consulte/trate o resultado fiscal pendente da NFS-e #'.$document->id);

                        return;
                    }
                    $this->step($operation, $key, 'started', 'Solicitando cancelamento fiscal.');
                    if (! $service->cancelar($document, '9', $operation->justification, $user->id)) {
                        $this->step($operation, $key, 'failed', $service->getMessage() ?: 'Cancelamento fiscal pendente.');
                        $this->pause($operation, $service->getMessage() ?: 'Trate a pendência fiscal antes de retomar.');

                        return;
                    }
                    if (! $document->fresh()->isFiscalCancellationConfirmed()) {
                        $this->pause($operation, 'Aguardando confirmação fiscal do cancelamento.', true);

                        return;
                    }
                    $this->step($operation, $key, 'completed', 'Cancelamento fiscal confirmado.');
                }
                // A receipt arriving after fiscal cancellation must stop all further progress.
                if ($errors = $this->blockers($invoice->fresh())) {
                    $this->pause($operation, implode(' ', $errors));

                    return;
                }
                $waiting = false;
                foreach ($invoice->receivableLinks()->where('active_slot', 1)->where('origin', 'generated')->get() as $link) {
                    foreach ($link->accountReceivable->installments as $installment) {
                        foreach ([$installment->bankSlips()->withTrashed()->get(), $installment->pixCharges()->withTrashed()->get()] as $charges) {
                            foreach ($charges as $charge) {
                                $this->assertAdministrator($user, $invoice);
                                if ($errors = $this->blockers($invoice->fresh())) {
                                    $this->pause($operation, implode(' ', $errors));

                                    return;
                                }
                                $waiting = $this->cancelCharge($operation, $charge, $user) || $waiting;
                            }
                        }
                    }
                }
                if ($waiting) {
                    $this->pause($operation, 'Aguardando confirmação de cancelamento das cobranças. As ordens ainda não foram liberadas.', true);

                    return;
                }
                $this->complete($operation, $user);
            } catch (\Throwable $exception) {
                report($exception);
                $this->pause($operation, $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage());
            }
        });
    }

    private function cancelCharge(InvoiceOperation $operation, BankSlip|PixCharge $charge, User $user): bool
    {
        $key = class_basename($charge).':'.$charge->id;
        if (in_array($charge->status->value, ['canceled', 'payment_returned'], true)) {
            $this->step($operation, $key, 'completed', 'Cobrança encerrada pelo provedor.');

            return false;
        }
        $unsent = in_array($charge->status->value, ['pending_registration', 'registration_failed'], true) && ! $charge->request_sent_at && ! $charge->request_snapshot;
        if ($unsent) {
            if (! $charge->trashed()) {
                app(BankChargeLifecycleService::class)->remove($charge, $operation->company_id, $user, $operation->justification);
            }
            $this->step($operation, $key, 'completed', 'Cobrança nunca enviada desativada localmente.');

            return false;
        }
        if ((in_array($charge->status->value, ['registered', 'expired'], true) || ($charge->status->value === 'cancel_pending' && $charge->operation_status === 'failed')) && ! in_array($charge->operation_status, ['sending', 'pending', 'uncertain'], true)) {
            app(BankChargeLifecycleService::class)->cancel($charge, $operation->company_id, $user, $operation->justification);
        }
        $charge->refresh();
        if (in_array($charge->status->value, ['canceled', 'payment_returned'], true)) {
            $this->step($operation, $key, 'completed', 'Cancelamento confirmado.');

            return false;
        }
        if (in_array($charge->status->value, ['uncertain', 'registration_failed', 'needs_review'], true) || $charge->operation_status === 'failed') {
            throw ValidationException::withMessages(['charge' => 'Consulte/trate a cobrança #'.$charge->id.' antes de retomar: '.$charge->status->description()]);
        }
        $this->step($operation, $key, 'waiting', 'Cobrança pendente: '.$charge->status->description());

        return true;
    }

    private function complete(InvoiceOperation $operation, User $user): void
    {
        DB::transaction(function () use ($operation, $user): void {
            $invoice = Invoice::where('company_id', $operation->company_id)->lockForUpdate()->findOrFail($operation->invoice_id);
            $operation->refresh();
            if (! $operation->status->isActive()) {
                return;
            }
            $this->assertAdministrator($user, $invoice);
            foreach ($invoice->accountReceivables()->lockForUpdate()->get() as $title) {
                $title->installments()->lockForUpdate()->get();
            }
            $errors = $this->blockers($invoice);
            if ($errors !== []) {
                throw ValidationException::withMessages(['invoice' => $errors]);
            }
            // Recheck fiscal state under the invoice lock before releasing anything.
            foreach ($invoice->fiscalDocuments()->lockForUpdate()->get() as $document) {
                if ($document->status !== FiscalStatus::CANCELLED || $document->hasOpenFiscalIssue()) {
                    throw ValidationException::withMessages(['invoice' => 'Ainda existe documento fiscal ou tratamento pendente.']);
                }
            }
            $links = $invoice->receivableLinks()->where('active_slot', 1)->lockForUpdate()->get();
            foreach ($links as $link) {
                $title = $link->accountReceivable;
                if ($link->origin === 'generated') {
                    foreach ($title->installments as $installment) {
                        foreach ([$installment->bankSlips()->withTrashed()->lockForUpdate()->get(), $installment->pixCharges()->withTrashed()->lockForUpdate()->get()] as $charges) {
                            foreach ($charges as $charge) {
                                $unsentRemoved = $charge->trashed() && ! $charge->request_sent_at && ! $charge->request_snapshot;
                                if (! $unsentRemoved && ! in_array($charge->status->value, ['canceled', 'payment_returned'], true)) {
                                    throw ValidationException::withMessages(['invoice' => 'Cobrança externa ainda não encerrada.']);
                                }
                            }
                        }
                        $installment->update(['status' => ReceivableStatus::CANCELLED]);
                    }
                    $title->update(['status' => ReceivableStatus::CANCELLED]);
                } else {
                    $title->update($link->original_links);
                }
                app(InvoiceFinancialLinkService::class)->release($link, 'billing_reversed');
            }
            $invoice->update(['status' => Status::CANCELLED, 'cancellation_reason' => CancellationReason::REBILLING, 'canceled_at' => now(), 'canceled_by' => $user->id, 'updated_by' => $user->id]);
            foreach ($invoice->serviceOrders()->where('company_id', $invoice->company_id)->lockForUpdate()->get() as $order) {
                $order->update(['invoice_id' => null, 'status' => State::CLOSED, 'updated_by' => $user->id]);
            }
            foreach ($invoice->latestConfirmation->sources()->whereNull('released_at')->get() as $source) {
                $source->update(['released_at' => now(), 'release_reason' => 'billing_reversed', 'released_by' => $user->id]);
            }
            $this->step($operation, 'release_orders', 'completed', 'Ordens encerradas e disponíveis para faturar em outro momento.');
            $operation->update(['status' => OperationStatus::COMPLETED, 'completed_at' => now(), 'last_message' => 'Faturamento revertido integralmente. Fatura e venda anteriores preservadas; nenhuma nova fatura foi criada.']);
            app(AuditRecorder::class)->recordModelEvent($invoice, 'invoice.billing_reversed', 'Faturamento de serviços revertido integralmente', actorUserId: $user->id, metadata: ['operation_id' => $operation->id]);
        });
    }

    private function step(InvoiceOperation $operation, string $key, string $state, string $message): void
    {
        $operation->refresh();
        $operation->update(['steps' => [...($operation->steps ?? []), $key => ['state' => $state, 'message' => $message, 'at' => now()->toIso8601String()]]]);
    }

    private function pause(InvoiceOperation $operation, string $message, bool $external = false): void
    {
        $operation->update(['status' => $external ? OperationStatus::AWAITING_EXTERNAL : OperationStatus::NEEDS_INTERVENTION, 'last_message' => $message]);
    }

    private function assertAdministrator(User $user, Invoice $invoice): void
    {
        if (! $this->canManage($user, $invoice)) {
            throw ValidationException::withMessages(['administrator' => 'O administrador perdeu acesso durante a operação. Outro administrador deve retomar o processamento.']);
        }
    }
}
