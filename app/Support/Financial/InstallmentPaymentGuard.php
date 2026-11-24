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
        $payment = $installment->payments()->effective()->lockForUpdate()->first();
        if ($payment) {
            $charges = [];
            foreach (['interest_amount', 'fine_amount', 'discount_amount'] as $field) {
                $charges[$field] = Money::decimal(Money::stored($payment, 'returned_amount') < Money::stored($payment) ? Money::stored($payment, $field) : 0);
            }
            self::validate($installment, $account, Money::decimal(Money::stored($payment) - Money::stored($payment, 'returned_amount')), $charges, $payment->id, true);
        }
    }

    public static function validate(Model $installment, Model $account, float|string $amount, array $charges, ?int $replacedPaymentId = null, bool $allowZero = false): void
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

        $due = Money::stored($installment, 'original_amount');
        foreach (['interest_amount' => 1, 'fine_amount' => 1, 'discount_amount' => -1] as $field => $sign) {
            $due += $sign * self::cents($charges[$field] ?? 0, $field);
        }

        foreach ($installment->payments()->effective()->when($replacedPaymentId, fn ($query) => $query->whereKeyNot($replacedPaymentId))->lockForUpdate()->get() as $payment) {
            $paid += Money::stored($payment) - Money::stored($payment, 'returned_amount');
            if (Money::stored($payment, 'returned_amount') < Money::stored($payment)) {
                $due += Money::stored($payment, 'interest_amount') + Money::stored($payment, 'fine_amount') - Money::stored($payment, 'discount_amount');
            }
        }

        if ($paid > $due) {
            throw ValidationException::withMessages([
                'amount' => ['O pagamento não pode exceder o saldo ajustado por juros, multa e desconto.'],
            ]);
        }
    }

    private static function cents(mixed $value, string $field): int
    {
        $cents = Money::cents($value);
        if ($cents < 0) {
            throw ValidationException::withMessages([$field => ['Informe um valor válido e não negativo.']]);
        }

        return $cents;
    }
}
