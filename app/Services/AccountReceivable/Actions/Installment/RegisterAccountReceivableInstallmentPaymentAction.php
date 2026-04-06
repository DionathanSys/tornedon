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
            $validated = AccountReceivableInstallmentValidator::validatePayment($data);
            $payment = AccountReceivableInstallmentPayment::create($validated);

            $totals = $this->calculateInstallmentTotals();

            $this->installment->update([
                'interest_amount' => $totals['interest'],
                'fine_amount' => $totals['fine'],
                'discount_amount' => $totals['discount'],
                'due_amount' => $totals['due_amount'],
                'received_amount' => $totals['received'],
                'balance_amount' => $totals['balance'],
                'received_date' => $totals['status'] === Status::RECEIVED->value ? $validated['payment_date'] : null,
                'status' => $totals['status'],
            ]);

            $this->setSuccess();

            return $payment;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados de recebimento', $e->errors());
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
        $received = round((float) $this->installment->payments()->sum('amount'), 2);
        $interest = round((float) $this->installment->payments()->sum('interest_amount'), 2);
        $fine = round((float) $this->installment->payments()->sum('fine_amount'), 2);
        $discount = round((float) $this->installment->payments()->sum('discount_amount'), 2);
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
