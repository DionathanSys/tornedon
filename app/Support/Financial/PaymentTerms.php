<?php

namespace App\Support\Financial;

use App\Enum\Payment\Condition;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

final class PaymentTerms
{
    public static function rules(string $condition = 'payment_condition', string $details = 'payment_condition_details'): array
    {
        return [
            $details => ['nullable', 'array:installments,first_due_days,interval_days'],
            "$details.installments" => ["exclude_unless:$condition,personalizado", 'required', 'integer', 'min:1', 'max:999'],
            "$details.first_due_days" => ["exclude_unless:$condition,personalizado", 'required', 'integer', 'min:0'],
            "$details.interval_days" => ["exclude_unless:$condition,personalizado", 'required', 'integer', 'min:1', 'max:365'],
        ];
    }

    public static function details(?Condition $condition, ?array $details): ?array
    {
        if ($condition !== Condition::CUSTOM) {
            return null;
        }

        $data = Validator::make([
            'payment_condition' => $condition->value,
            'payment_condition_details' => $details,
        ], self::rules())->validate();

        return array_map('intval', $data['payment_condition_details']);
    }

    public static function count(?Condition $condition, ?array $details = null): int
    {
        return $condition === Condition::CUSTOM
            ? self::details($condition, $details)['installments']
            : max(1, $condition?->installments() ?: 1);
    }

    public static function interval(?Condition $condition, ?array $details = null): int
    {
        return $condition === Condition::CUSTOM
            ? self::details($condition, $details)['interval_days']
            : max(30, $condition?->days() ?? 0);
    }

    public static function firstDueDate(?Condition $condition, Carbon $baseDate, ?array $details = null): Carbon
    {
        $days = $condition === Condition::CUSTOM
            ? self::details($condition, $details)['first_due_days']
            : ($condition?->isInstallment() ? 30 : ($condition?->days() ?? 0));

        return $baseDate->copy()->addDays($days);
    }

    public static function description(?Condition $condition, ?array $details = null): string
    {
        if ($condition !== Condition::CUSTOM || ! $details) {
            return $condition?->description() ?? 'Não definida';
        }

        return sprintf('Personalizado: %dx, primeiro vencimento em %d dias, intervalo de %d dias',
            $details['installments'], $details['first_due_days'], $details['interval_days']);
    }
}
