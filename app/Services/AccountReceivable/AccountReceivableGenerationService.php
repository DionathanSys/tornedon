<?php

namespace App\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
                $this->setError('Documento fiscal ainda não autorizado para gerar contas a receber.');
                return false;
            }

            if ($invoice->status === InvoiceStatus::CANCELLED || $invoice->canceled) {
                $this->setError('Não é permitido gerar contas a receber para fatura cancelada.');
                return false;
            }

            if ($invoice->accountReceivables()->exists()) {
                $this->setSuccess('Contas a receber já existentes para a fatura.');

                Log::info('AccountReceivableGenerationService: contas a receber já existem, geração ignorada', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                return true;
            }

            $paymentMethod = $this->resolvePaymentMethod($invoice);
            $paymentCondition = $this->resolvePaymentCondition($invoice);

            if (! $paymentMethod || ! $paymentCondition) {
                return false;
            }

            $installments = $this->buildInstallments($invoice, $paymentCondition);

            if (empty($installments)) {
                $this->setError('Não foi possível montar parcelas de contas a receber para a fatura.');
                return false;
            }

            $documentNumber = $fiscalDocument->document_number
                ?? $fiscalDocument->document_key
                ?? $invoice->invoice_number;

            DB::transaction(function () use (
                $invoice,
                $fiscalDocument,
                $paymentMethod,
                $installments,
                $documentNumber
            ): void {
                foreach ($installments as $installment) {
                    $this->upsertInstallment(
                        invoice: $invoice,
                        fiscalDocument: $fiscalDocument,
                        paymentMethod: $paymentMethod,
                        installment: $installment,
                        documentNumber: $documentNumber
                    );
                }
            });

            $this->setSuccess('Contas a receber geradas com sucesso.');

            Log::info('AccountReceivableGenerationService: contas a receber geradas', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'invoice_id'         => $invoice->id,
                'fiscal_document_id' => $fiscalDocument->id,
                'installments'       => count($installments),
                'payment_method'     => $paymentMethod->value,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar contas a receber: ' . $e->getMessage());

            Log::error('AccountReceivableGenerationService: falha ao gerar contas a receber', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'error_code'         => $this->getErrorCode(),
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
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
                    ? 'Forma de pagamento obrigatória não encontrada na fatura.'
                    : 'Fatura possui múltiplas formas de pagamento. Padronize antes de gerar o contas a receber.'
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
                    ? 'Condição de pagamento obrigatória não encontrada na fatura.'
                    : 'Fatura possui múltiplas condições de pagamento. Padronize antes de gerar o contas a receber.'
            );

            return null;
        }

        return $conditions->first();
    }

    private function buildInstallments(Invoice $invoice, PaymentCondition $condition): array
    {
        $netValue = round((float) $invoice->netValue, 2);

        if ($netValue <= 0) {
            $this->setError('Valor líquido da fatura inválido para gerar contas a receber.');
            return [];
        }

        $totalCents = (int) round($netValue * 100);
        $installmentsCount = max(1, $condition->installments() ?: 1);
        $baseCents = intdiv($totalCents, $installmentsCount);
        $remainder = $totalCents - ($baseCents * $installmentsCount);
        $baseDate = Carbon::parse($invoice->invoice_date ?? now()->toDateString());

        $installments = [];

        for ($i = 1; $i <= $installmentsCount; $i++) {
            $amountCents = $baseCents + ($i === $installmentsCount ? $remainder : 0);

            $installments[] = [
                'sequence_number' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'due_date' => $this->resolveDueDate($condition, $baseDate, $i)->toDateString(),
                'due_amount' => round($amountCents / 100, 2),
                'installment_number' => $i,
                'installments_count' => $installmentsCount,
            ];
        }

        $sum = round(array_sum(array_column($installments, 'due_amount')), 2);

        if ($sum !== $netValue) {
            $this->setError('Falha de integridade financeira ao gerar parcelas de contas a receber.');
            return [];
        }

        return $installments;
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

    private function upsertInstallment(
        Invoice $invoice,
        FiscalDocument $fiscalDocument,
        PaymentMethod $paymentMethod,
        array $installment,
        ?string $documentNumber
    ): void {
        $query = AccountReceivable::query()
            ->where('invoice_id', $invoice->id)
            ->where('sequence_number', $installment['sequence_number'])
            ->where(function ($q) use ($fiscalDocument): void {
                $q->where('fiscal_document_id', $fiscalDocument->id)
                    ->orWhereNull('fiscal_document_id');
            })
            ->orderByRaw('CASE WHEN fiscal_document_id IS NULL THEN 1 ELSE 0 END');

        $accountReceivable = $query->first() ?? new AccountReceivable();

        $accountReceivable->fill([
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'fiscal_document_id' => $fiscalDocument->id,
            'sequence_number' => $installment['sequence_number'],
            'status' => AccountReceivableStatus::PENDING->value,
            'due_date' => $installment['due_date'],
            'paid_date' => $installment['due_date'],
            'due_amount' => $installment['due_amount'],
            'paid_amount' => 0,
            'document_number' => $documentNumber,
            'description' => sprintf(
                'Parcela %d/%d gerada automaticamente da fatura %s e documento fiscal %s',
                $installment['installment_number'],
                $installment['installments_count'],
                $invoice->invoice_number,
                $documentNumber ?? '-'
            ),
            'paid' => false,
            'payment_method' => $paymentMethod->value,
        ]);

        $accountReceivable->save();
    }
}
