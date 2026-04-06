<?php

namespace App\Services\AccountPayable\Actions\Installment;

use App\Enum\AccountPayable\Status;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Services\AccountPayable\Validators\AccountPayableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegisterAccountPayableInstallmentPaymentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountPayableInstallment $installment) {}

    public function execute(array $data): ?AccountPayableInstallmentPayment
    {
        try {
            Log::info('Iniciando registro de pagamento de parcela', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
                'company_id' => $this->installment->company_id,
                'payload' => $data,
                'installment_snapshot' => [
                    'original_amount' => $this->installment->original_amount,
                    'original_amount_raw' => $this->installment->getRawOriginal('original_amount'),
                    'due_amount' => $this->installment->due_amount,
                    'due_amount_raw' => $this->installment->getRawOriginal('due_amount'),
                    'paid_amount' => $this->installment->paid_amount,
                    'paid_amount_raw' => $this->installment->getRawOriginal('paid_amount'),
                    'balance_amount' => $this->installment->balance_amount,
                    'balance_amount_raw' => $this->installment->getRawOriginal('balance_amount'),
                ],
            ]);

            $validated = AccountPayableInstallmentValidator::validatePayment($data);
            Log::info('Payload de pagamento validado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'validated' => $validated,
            ]);

            $payment = AccountPayableInstallmentPayment::create($validated);
            Log::info('Pagamento criado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'payment_id' => $payment->id,
                'installment_id' => $this->installment->id,
                'payment_snapshot' => [
                    'amount' => $payment->amount,
                    'amount_raw' => $payment->getRawOriginal('amount'),
                    'interest_amount' => $payment->interest_amount,
                    'interest_amount_raw' => $payment->getRawOriginal('interest_amount'),
                    'fine_amount' => $payment->fine_amount,
                    'fine_amount_raw' => $payment->getRawOriginal('fine_amount'),
                    'discount_amount' => $payment->discount_amount,
                    'discount_amount_raw' => $payment->getRawOriginal('discount_amount'),
                ],
            ]);

            $totals = $this->calculateInstallmentTotals();
            Log::info('Totais recalculados da parcela a pagar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'totals' => $totals,
            ]);

            $this->installment->update([
                'interest_amount' => $totals['interest'],
                'fine_amount' => $totals['fine'],
                'discount_amount' => $totals['discount'],
                'due_amount' => $totals['due_amount'],
                'paid_amount' => $totals['paid'],
                'balance_amount' => $totals['balance'],
                'paid_date' => $totals['status'] === Status::PAID->value ? $validated['payment_date'] : null,
                'status' => $totals['status'],
            ]);

            $this->installment->refresh();
            Log::info('Parcela a pagar atualizada apos registro do pagamento', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'installment_snapshot' => [
                    'interest_amount' => $this->installment->interest_amount,
                    'interest_amount_raw' => $this->installment->getRawOriginal('interest_amount'),
                    'fine_amount' => $this->installment->fine_amount,
                    'fine_amount_raw' => $this->installment->getRawOriginal('fine_amount'),
                    'discount_amount' => $this->installment->discount_amount,
                    'discount_amount_raw' => $this->installment->getRawOriginal('discount_amount'),
                    'due_amount' => $this->installment->due_amount,
                    'due_amount_raw' => $this->installment->getRawOriginal('due_amount'),
                    'paid_amount' => $this->installment->paid_amount,
                    'paid_amount_raw' => $this->installment->getRawOriginal('paid_amount'),
                    'balance_amount' => $this->installment->balance_amount,
                    'balance_amount_raw' => $this->installment->getRawOriginal('balance_amount'),
                    'status' => $this->installment->status?->value ?? $this->installment->status,
                ],
            ]);

            $this->setSuccess();

            Log::info('Pagamento de parcela registrado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'payment_id' => $payment->id,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
            ]);

            return $payment;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados de pagamento', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar pagamento de parcela no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao registrar pagamento da parcela');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        }
    }

    private function calculateInstallmentTotals(): array
    {
        $payments = $this->installment->payments()
            ->get(['amount', 'interest_amount', 'fine_amount', 'discount_amount']);

        $paid = round((float) $payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->amount), 2);
        $interest = round((float) $payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->interest_amount), 2);
        $fine = round((float) $payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->fine_amount), 2);
        $discount = round((float) $payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->discount_amount), 2);
        $dueAmount = round((float) $this->installment->original_amount + $interest + $fine - $discount, 2);
        $balance = max(round($dueAmount - $paid, 2), 0);

        return [
            'paid' => $paid,
            'interest' => $interest,
            'fine' => $fine,
            'discount' => $discount,
            'due_amount' => $dueAmount,
            'balance' => $balance,
            'status' => $balance <= 0
                ? Status::PAID->value
                : ($paid > 0 ? Status::PARTIALLY_PAID->value : Status::PENDING->value),
        ];
    }
}
