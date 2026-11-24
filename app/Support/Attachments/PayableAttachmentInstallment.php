<?php

namespace App\Support\Attachments;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

final class PayableAttachmentInstallment
{
    public static function rules(Model $owner): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('account_payable_installments', 'id')
                ->where('company_id', $owner->company_id)
                ->where('account_payable_id', $owner instanceof AccountPayable ? $owner->id : 0),
        ];
    }

    public static function options(Model $owner): array
    {
        if (! $owner instanceof AccountPayable) {
            return [];
        }

        return $owner->installments()->where('company_id', $owner->company_id)
            ->orderBy('sequence_number')->get()
            ->mapWithKeys(fn (AccountPayableInstallment $installment): array => [$installment->id => self::label($installment)])
            ->all();
    }

    public static function label(AccountPayableInstallment $installment): string
    {
        return 'Parcela '.$installment->sequence_number.' · '.($installment->due_date?->format('d/m/Y') ?? '-')
            .' · '.Number::currency((float) $installment->due_amount, 'BRL', locale: 'pt_BR');
    }
}
