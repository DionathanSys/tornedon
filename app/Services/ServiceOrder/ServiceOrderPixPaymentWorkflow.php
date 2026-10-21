<?php

namespace App\Services\ServiceOrder;

use App\Enum\Financial\PixChargeStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\State;
use App\Jobs\RegisterPixChargeJob;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\PixCharge;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Financial\Banking\Providers\IntegraBancosPayloadBuilder;
use App\Services\Financial\Pix\PixChargeIssuanceService;
use App\Services\Financial\Pix\PixEligibilityService;
use App\Services\Invoice\InvoiceService;
use App\Support\Financial\InstallmentPaymentGuard;
use App\Support\ServiceOrderPixPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceOrderPixPaymentWorkflow
{
    public function execute(ServiceOrder $order, User $user): PixCharge
    {
        abort_unless(ServiceOrderPixPayment::allows($user, (int) $order->company_id), 403);

        return DB::transaction(function () use ($order, $user): PixCharge {
            $order = ServiceOrder::query()->where('company_id', $order->company_id)->lockForUpdate()->findOrFail($order->id);
            abort_unless(ServiceOrderPixPayment::allows($user, (int) $order->company_id), 403);
            $this->ensure($order->status !== State::CANCELLED, 'A ordem de serviço está cancelada.');

            // Never replace an existing bank operation, including an uncertain or expired one.
            $existing = PixCharge::query()->where('company_id', $order->company_id)->where('service_order_id', $order->id)->latest('id')->first();
            if ($existing) {
                if ($existing->status === PixChargeStatus::PENDING_REGISTRATION) {
                    RegisterPixChargeJob::dispatch($existing->id)->afterCommit();
                }

                return $existing;
            }

            $settings = ServiceOrderPixPayment::entitlement((int) $order->company_id)?->metadata ?? [];
            $accountId = (int) ($settings['financial_account_id'] ?? 0);
            $categoryId = (int) ($settings['financial_category_id'] ?? 0);
            $this->ensure(array_key_exists($accountId, app(PixEligibilityService::class)->financialAccountOptions((int) $order->company_id)), 'Configure uma conta financeira com conexão PIX ativa nos direitos da empresa.');
            $this->ensure(FinancialCategory::query()->where('company_id', $order->company_id)->whereKey($categoryId)
                ->where('is_active', true)->where('allow_receivable', true)->exists(), 'Configure uma categoria financeira válida nos direitos da empresa.');
            $this->ensure(filled($order->customer?->name) && filled($order->customer?->document_number), 'O cliente precisa possuir nome e documento para a cobrança PIX.');

            if ($order->status === State::OPEN) {
                $order->update(['payment_method' => Method::PIX, 'payment_condition' => Condition::CASH, 'updated_by' => $user->id]);
                $workflow = app(CloseServiceOrderWorkflow::class);
                $this->ensure($workflow->execute($order, $user->id, false), $workflow->getMessageUser() ?: 'Não foi possível encerrar a OS.');
                $order->refresh();
            }

            if (! $order->invoice_id) {
                $workflow = app(InvoiceServiceOrderWorkflow::class);
                $this->ensure($workflow->execute($order, $user->id), $workflow->getMessageUser() ?: 'Não foi possível faturar a OS.');
                $order->refresh();
            }

            $invoice = Invoice::query()->where('company_id', $order->company_id)->lockForUpdate()->findOrFail($order->invoice_id);
            $this->ensure(! $invoice->canceled && $invoice->serviceOrders()->count() === 1
                && (int) $invoice->customer_id === (int) $order->customer_id
                && $invoice->serviceOrders()->whereKey($order->id)->exists(), 'A fatura deve pertencer exclusivamente a esta OS.');
            $linkedRequisitionId = $order->requisition()->value('id');
            $this->ensure(! $invoice->requisitions()->when($linkedRequisitionId, fn ($query) => $query->whereKeyNot($linkedRequisitionId))->exists(), 'A fatura possui requisições de outros atendimentos.');

            if (! $invoice->confirmed) {
                $service = app(InvoiceService::class);
                $result = $service->confirm($invoice, [
                    'payment_method' => Method::PIX->value,
                    'payment_condition' => Condition::CASH->value,
                    'financial_account_id' => $accountId,
                    'financial_category_id' => $categoryId,
                    'auto_pix_charge_issuance' => false,
                    'auto_register_receipt_on_due_date' => false,
                    'mark_as_received' => false,
                ], $user->id, generateFiscalDocuments: false);
                $this->ensure($result !== null, $service->getMessageUser() ?: 'Não foi possível confirmar a fatura.');
                $invoice->refresh();
            }

            $receivables = $invoice->accountReceivables()->with('installments')->get();
            $this->ensure($invoice->payment_method === Method::PIX && $invoice->payment_condition === Condition::CASH && $receivables->count() === 1, 'O financeiro existente não é compatível com PIX integral na OS.');
            $receivable = $receivables->first();
            $this->ensure((int) $receivable->company_id === (int) $order->company_id
                && (int) $receivable->customer_id === (int) $order->customer_id
                && $receivable->payment_method === Method::PIX && $receivable->installments->count() === 1
                && ! $receivable->auto_register_receipt_on_due_date && $receivable->status->value !== 'cancelled', 'O recebível deve possuir uma única parcela PIX sem baixa automática por vencimento.');
            $installment = InstallmentPaymentGuard::lock($receivable->installments->first());
            $this->ensure((int) $installment->company_id === (int) $order->company_id
                && (int) $installment->financial_account_id === $accountId
                && $installment->status->value !== 'cancelled'
                && ! $installment->payments()->exists()
                && (int) round((float) $installment->balance_amount * 100) === (int) round((float) $invoice->netValue * 100), 'A parcela existente possui pagamento ou configuração incompatível.');
            $this->ensure(! $installment->pixCharges()->exists(), 'A parcela já possui cobrança PIX. Utilize o acompanhamento financeiro existente.');
            $this->ensure(! $installment->bankSlips()->exists(), 'A parcela já possui boleto. Solicite a revisão do financeiro antes de cobrar via PIX.');
            app(PixEligibilityService::class)->resolveConnection($installment);
            $charge = app(PixChargeIssuanceService::class)->preparePixCharge($installment);
            $this->ensure($charge !== null, 'Não foi possível preparar a cobrança PIX.');
            $this->ensure($charge->connection?->billingProvider?->key === 'integrabancos', 'O recebimento na OS requer uma conexão IntegraBancos.');
            // Validate the bank contract before committing the commercial/financial changes.
            $payload = app(IntegraBancosPayloadBuilder::class)->build($charge);
            $charge->update(['service_order_id' => $order->id, 'requested_by' => $user->id, 'request_snapshot' => $payload]);
            RegisterPixChargeJob::dispatch($charge->id)->afterCommit();

            return $charge;
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['pixPayment' => $message]);
        }
    }
}
