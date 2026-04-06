<?php

namespace App\Services\AccountReceivable\Actions\Installment;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegisterAccountReceivableInstallmentPaymentAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountReceivableInstallment $installment) {}

    public function execute(array $data): ?AccountReceivableInstallmentPayment
    {
        try {
            Log::info('Iniciando registro de recebimento de parcela', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_receivable_id' => $this->installment->account_receivable_id,
                'company_id' => $this->installment->company_id,
                'payload' => $data,
                'installment_snapshot' => [
                    'original_amount' => $this->installment->original_amount,
                    'original_amount_raw' => $this->installment->getRawOriginal('original_amount'),
                    'due_amount' => $this->installment->due_amount,
                    'due_amount_raw' => $this->installment->getRawOriginal('due_amount'),
                    'received_amount' => $this->installment->received_amount,
                    'received_amount_raw' => $this->installment->getRawOriginal('received_amount'),
                    'balance_amount' => $this->installment->balance_amount,
                    'balance_amount_raw' => $this->installment->getRawOriginal('balance_amount'),
                ],
            ]);

            $validated = AccountReceivableInstallmentValidator::validatePayment($data);
            Log::info('Payload de recebimento validado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'validated' => $validated,
            ]);

            $payment = AccountReceivableInstallmentPayment::create($validated);
            Log::info('Recebimento criado', [
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
            Log::info('Totais recalculados da parcela a receber', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'totals' => $totals,
            ]);

            $this->installment->update([
                'interest_amount' => $totals['interest'],
                'fine_amount' => $totals['fine'],
                'discount_amount' => $totals['discount'],
                'due_amount' => $totals['due_amount'],
                'received_amount' => $totals['received'],
                'received_date' => $totals['status'] === Status::RECEIVED->value ? $validated['payment_date'] : null,
                'status' => $totals['status'],
            ]);

            $this->installment->refresh();
            Log::info('Parcela a receber atualizada apos registro do recebimento', [
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
                    'received_amount' => $this->installment->received_amount,
                    'received_amount_raw' => $this->installment->getRawOriginal('received_amount'),
                    'balance_amount' => $this->installment->balance_amount,
                    'balance_amount_raw' => $this->installment->getRawOriginal('balance_amount'),
                    'status' => $this->installment->status?->value ?? $this->installment->status,
                ],
            ]);

            $this->setSuccess();

            return $payment;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados de recebimento', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar recebimento da parcela no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'installment_id' => $this->installment->id,
                'payload' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao registrar recebimento da parcela');

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

        $received = round((float) $payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->amount), 2);
        $interest = round((float) $payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->interest_amount), 2);
        $fine = round((float) $payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->fine_amount), 2);
        $discount = round((float) $payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->discount_amount), 2);
        $dueAmount = round((float) $this->installment->original_amount + $interest + $fine - $discount, 2);
        $balance = max(round($dueAmount - $received, 2), 0);

        return [
            'received' => $received,
            'interest' => $interest,
            'fine' => $fine,
            'discount' => $discount,
            'due_amount' => $dueAmount,
            'balance' => $balance,
            'status' => $balance <= 0
                ? Status::RECEIVED->value
                : ($received > 0 ? Status::PARTIALLY_RECEIVED->value : Status::PENDING->value),
        ];
    }
}
