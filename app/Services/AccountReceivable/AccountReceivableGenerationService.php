<?php

namespace App\Services\AccountReceivable;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AccountReceivableGenerationService
{
    use HandlesServiceResponse;

    public function generateFromFiscalDocument(FiscalDocument $fiscalDocument): bool
    {
        $this->resetResponse();

        try {
            $fiscalDocument->loadMissing([
                'invoice.requisitions.items',
                'invoice.serviceOrders.items',
            ]);

            $invoice = $fiscalDocument->invoice;

            if (! $invoice) {
                $this->setError('Documento fiscal sem fatura vinculada.');
                return false;
            }

            if (! $this->isFiscalDocumentAuthorized($fiscalDocument)) {
                $this->setError('Documento fiscal ainda nao autorizado para gerar contas a receber.');
                return false;
            }

            if ($invoice->status === InvoiceStatus::CANCELLED || $invoice->canceled) {
                $this->setError('Nao e permitido gerar contas a receber para fatura cancelada.');
                return false;
            }

            if ($invoice->accountReceivables()->exists()) {
                $this->setSuccess('Contas a receber ja existentes para a fatura.');

                Log::info('AccountReceivableGenerationService: contas a receber ja existem, geracao ignorada', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return true;
            }

            $paymentMethod = $this->resolvePaymentMethod($invoice);
            $paymentCondition = $this->resolvePaymentCondition($invoice);

            if (! $paymentMethod || ! $paymentCondition) {
                return false;
            }

            $receivablePayload = $this->buildReceivablePayload($invoice, $fiscalDocument, $paymentMethod, $paymentCondition);

            if ($receivablePayload === null) {
                return false;
            }

            $service = app(AccountReceivableService::class);
            $accountReceivable = $service->create($receivablePayload, 0);

            if ($service->hasError() || $accountReceivable === null) {
                $this->setError(
                    $service->getMessage(),
                    $service->getErrors(),
                    $service->getStatus(),
                    $service->getErrorCode()
                );
                return false;
            }

            $this->setSuccess('Contas a receber geradas com sucesso.');

            Log::info('AccountReceivableGenerationService: contas a receber geradas', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'fiscal_document_id' => $fiscalDocument->id,
                'account_receivable_id' => $accountReceivable->id,
                'installments' => $paymentCondition->installments(),
                'payment_method' => $paymentMethod->value,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar contas a receber: ' . $e->getMessage());

            Log::error('AccountReceivableGenerationService: falha ao gerar contas a receber', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function isFiscalDocumentAuthorized(FiscalDocument $fiscalDocument): bool
    {
        return match ($fiscalDocument->document_type) {
            DocumentModel::NFSE => $fiscalDocument->nfse_status === NfeStatus::AUTHORIZED,
            default => $fiscalDocument->nfe_status === NfeStatus::AUTHORIZED,
        };
    }

    private function resolvePaymentMethod(Invoice $invoice): ?PaymentMethod
    {
        if ($invoice->payment_method instanceof PaymentMethod) {
            return $invoice->payment_method;
        }

        if (is_string($invoice->payment_method)) {
            $method = PaymentMethod::tryFrom($invoice->payment_method);

            if ($method) {
                return $method;
            }
        }

        $methods = collect()
            ->merge($invoice->requisitions->pluck('payment_method'))
            ->merge($invoice->serviceOrders->pluck('payment_method'))
            ->map(function ($method): ?PaymentMethod {
                if ($method instanceof PaymentMethod) {
                    return $method;
                }

                if (is_string($method)) {
                    return PaymentMethod::tryFrom($method);
                }

                return null;
            })
            ->filter()
            ->unique(fn (PaymentMethod $method) => $method->value)
            ->values();

        if ($methods->count() !== 1) {
            $this->setError(
                $methods->isEmpty()
                    ? 'Forma de pagamento obrigatoria nao encontrada na fatura.'
                    : 'Fatura possui multiplas formas de pagamento. Padronize antes de gerar o contas a receber.'
            );

            return null;
        }

        return $methods->first();
    }

    private function resolvePaymentCondition(Invoice $invoice): ?PaymentCondition
    {
        if ($invoice->payment_condition instanceof PaymentCondition) {
            return $invoice->payment_condition;
        }

        if (is_string($invoice->payment_condition)) {
            $condition = PaymentCondition::tryFrom($invoice->payment_condition);

            if ($condition) {
                return $condition;
            }
        }

        $conditions = collect()
            ->merge($invoice->requisitions->pluck('payment_condition'))
            ->merge($invoice->serviceOrders->pluck('payment_condition'))
            ->map(function ($condition): ?PaymentCondition {
                if ($condition instanceof PaymentCondition) {
                    return $condition;
                }

                if (is_string($condition)) {
                    return PaymentCondition::tryFrom($condition);
                }

                return null;
            })
            ->filter()
            ->unique(fn (PaymentCondition $condition) => $condition->value)
            ->values();

        if ($conditions->count() !== 1) {
            $this->setError(
                $conditions->isEmpty()
                    ? 'Condicao de pagamento obrigatoria nao encontrada na fatura.'
                    : 'Fatura possui multiplas condicoes de pagamento. Padronize antes de gerar o contas a receber.'
            );

            return null;
        }

        return $conditions->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildReceivablePayload(
        Invoice $invoice,
        FiscalDocument $fiscalDocument,
        PaymentMethod $paymentMethod,
        PaymentCondition $condition
    ): ?array {
        $netValue = round((float) $invoice->netValue, 2);

        if ($netValue <= 0) {
            $this->setError('Valor liquido da fatura invalido para gerar contas a receber.');
            return null;
        }

        $installmentCount = max(1, $condition->installments() ?: 1);
        $firstDueDate = $this->resolveDueDate($condition, Carbon::parse($invoice->invoice_date ?? now()->toDateString()), 1);
        $documentNumber = $fiscalDocument->document_number
            ?? $fiscalDocument->document_key
            ?? $invoice->invoice_number;

        return [
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'fiscal_document_id' => $fiscalDocument->id,
            'sequence_number' => '01',
            'due_date' => $firstDueDate->toDateString(),
            'paid_date' => null,
            'due_amount' => $netValue,
            'paid_amount' => 0,
            'document_number' => $documentNumber,
            'description' => sprintf(
                'Conta a receber gerada automaticamente da fatura %s e documento fiscal %s',
                $invoice->invoice_number,
                $documentNumber ?? '-'
            ),
            'paid' => false,
            'payment_method' => $paymentMethod->value,
            'installment_count' => $installmentCount,
            'installment_due_mode' => 'interval_30_days',
        ];
    }

    private function resolveDueDate(PaymentCondition $condition, Carbon $baseDate, int $installmentNumber): Carbon
    {
        if ($condition->isCash() || $condition === PaymentCondition::CUSTOM) {
            return $baseDate->copy();
        }

        if ($condition->installments() > 1) {
            $daysStep = max($condition->days(), 30);
            return $baseDate->copy()->addDays($daysStep * $installmentNumber);
        }

        if ($condition->isTerm()) {
            return $baseDate->copy()->addDays($condition->days());
        }

        return $baseDate->copy();
    }
}
