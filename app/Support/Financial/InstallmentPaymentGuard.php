<?php

namespace App\Support\Financial;

use App\Models\AccountPayableInstallment;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class InstallmentPaymentGuard
{
    public static function lock(Model $installment): Model
    {
        $relation = $installment instanceof AccountPayableInstallment ? 'accountPayable' : 'accountReceivable';
        $account = $installment->{$relation}()->lockForUpdate()->firstOrFail();
        $locked = $installment->newQuery()->lockForUpdate()->findOrFail($installment->id);
        $locked->setRelation($relation, $account);

        return $locked;
    }

    /** The installment must be locked by the caller for the entire operation. */
    public static function validateExistingPayments(Model $installment, Model $account): void
    {
        $payment = $installment->payments()->first();
        if ($payment) {
            self::validate($installment, $account, (float) $payment->amount - (float) $payment->returned_amount, $payment->only(['interest_amount', 'fine_amount', 'discount_amount']), $payment->id, true);
        }
    }

    public static function validate(Model $installment, Model $account, float $amount, array $charges, ?int $replacedPaymentId = null, bool $allowZero = false): void
    {
        if ($installment->ignored_at && ! $allowZero) {
            throw ValidationException::withMessages(['ignored_at' => ['Restaure a consideração da parcela antes de registrar ou alterar uma baixa.']]);
        }
        if ((int) $installment->company_id !== (int) $account->company_id) {
            throw ValidationException::withMessages(['company_id' => ['Empresa da parcela divergente da conta.']]);
        }

        foreach ([$installment, $account] as $record) {
            $status = $record->status;
            if (($status instanceof BackedEnum ? $status->value : $status) === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Não é possível baixar uma conta ou parcela cancelada.']]);
            }
        }

        $paid = self::cents($amount, 'amount');
        if ($paid <= 0 && ! $allowZero) {
            throw ValidationException::withMessages(['amount' => ['O valor deve ser de pelo menos R$ 0,01.']]);
        }

        $due = self::cents((float) $installment->original_amount, 'original_amount');
        foreach (['interest_amount' => 1, 'fine_amount' => 1, 'discount_amount' => -1] as $field => $sign) {
            $due += $sign * self::cents((float) ($charges[$field] ?? 0), $field);
        }

        foreach ($installment->payments()->when($replacedPaymentId, fn ($query) => $query->whereKeyNot($replacedPaymentId))->get() as $payment) {
            $paid += self::cents((float) $payment->amount - (float) $payment->returned_amount, 'amount');
            $due += self::cents((float) $payment->interest_amount, 'interest_amount')
                + self::cents((float) $payment->fine_amount, 'fine_amount')
                - self::cents((float) $payment->discount_amount, 'discount_amount');
        }

        if ($paid > $due) {
            throw ValidationException::withMessages([
                'amount' => ['O pagamento não pode exceder o saldo ajustado por juros, multa e desconto.'],
            ]);
        }
    }

    private static function cents(float $value, string $field): int
    {
        if (! is_finite($value) || $value < 0) {
            throw ValidationException::withMessages([$field => ['Informe um valor válido e não negativo.']]);
        }

        return (int) round($value * 100);
    }
}
