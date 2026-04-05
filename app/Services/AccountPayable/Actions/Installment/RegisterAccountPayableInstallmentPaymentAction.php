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
            Log::debug('Iniciando registro de pagamento de parcela', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'installment_id' => $this->installment->id,
                'account_payable_id' => $this->installment->account_payable_id,
                'company_id' => $this->installment->company_id,
                'payload' => $data,
            ]);

            $validated = AccountPayableInstallmentValidator::validatePayment($data);
            $payment = AccountPayableInstallmentPayment::create($validated);

            $totals = $this->calculateInstallmentTotals();

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
        $paid = round((float) $this->installment->payments()->sum('amount'), 2);
        $interest = round((float) $this->installment->payments()->sum('interest_amount'), 2);
        $fine = round((float) $this->installment->payments()->sum('fine_amount'), 2);
        $discount = round((float) $this->installment->payments()->sum('discount_amount'), 2);
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
