<?php

namespace App\Support\Financial;

use Illuminate\Database\Eloquent\Model;

final class InstallmentPaymentTotals
{
    public static function calculate(Model $installment, bool $receivable): array
    {
        $payments = $installment->payments()->effective()->lockForUpdate()->get();
        $settled = $interest = $fine = $discount = 0;
        foreach ($payments as $payment) {
            $amount = Money::stored($payment);
            $returned = Money::stored($payment, 'returned_amount');
            $settled += $amount - $returned;
            if ($returned < $amount) {
                $interest += Money::stored($payment, 'interest_amount');
                $fine += Money::stored($payment, 'fine_amount');
                $discount += Money::stored($payment, 'discount_amount');
            }
        }
        $due = Money::stored($installment, 'original_amount') + $interest + $fine - $discount;
        $balance = max($due - $settled, 0);

        return [
            $receivable ? 'received' : 'paid' => Money::decimal($settled),
            'interest' => Money::decimal($interest), 'fine' => Money::decimal($fine), 'discount' => Money::decimal($discount),
            'due_amount' => Money::decimal($due), 'balance' => Money::decimal($balance),
            'status' => $balance <= 0 ? ($receivable ? 'received' : 'paid') : ($settled > 0 ? ($receivable ? 'partially_received' : 'partially_paid') : 'pending'),
        ];
    }
}
