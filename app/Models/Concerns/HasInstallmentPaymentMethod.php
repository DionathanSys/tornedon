<?php

namespace App\Models\Concerns;

use App\Enum\Payment\Method;
use App\Models\AccountPayableInstallment;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasInstallmentPaymentMethod
{
    protected function paymentMethod(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?Method {
                if ($value !== null) {
                    return Method::from($value);
                }

                $relationship = $this instanceof AccountPayableInstallment ? 'accountPayable' : 'accountReceivable';

                return $this->{$relationship}?->payment_method;
            },
            set: fn (Method|string|null $value): ?string => $value instanceof Method ? $value->value : $value,
        );
    }
}
