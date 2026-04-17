<?php

namespace App\Support\Financial;

use App\Enum\Payment\Condition as PaymentCondition;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class InstallmentSchedule
{
    public const FIXED_DAY_OF_MONTH = 'fixed_day_of_month';

    public const CUSTOM_INTERVAL_DAYS = 'custom_interval_days';

    /**
     * @return array{mode: string, fixed_day: int|null, interval_days: int|null}
     */
    public static function extractConfig(array &$data, string $defaultMode = PaymentCondition::DAYS_30->value): array
    {
        $config = [
            'mode' => (string) ($data['installment_due_mode'] ?? $defaultMode),
            'fixed_day' => isset($data['installment_fixed_day']) ? (int) $data['installment_fixed_day'] : null,
            'interval_days' => isset($data['installment_interval_days']) ? (int) $data['installment_interval_days'] : null,
        ];

        unset(
            $data['installment_due_mode'],
            $data['installment_fixed_day'],
            $data['installment_interval_days'],
        );

        return $config;
    }

    public static function dueDate(Carbon $baseDate, int $index, array $scheduleConfig = []): CarbonInterface
    {
        if ($index === 0) {
            return $baseDate->copy();
        }

        $mode = (string) ($scheduleConfig['mode'] ?? PaymentCondition::DAYS_30->value);

        if ($mode === self::FIXED_DAY_OF_MONTH) {
            $fixedDay = (int) ($scheduleConfig['fixed_day'] ?? $baseDate->day);
            $dueDate = $baseDate->copy()->addMonthsNoOverflow($index);

            return $dueDate->day(min($fixedDay, $dueDate->daysInMonth));
        }

        if ($mode === self::CUSTOM_INTERVAL_DAYS) {
            $daysStep = max(1, (int) ($scheduleConfig['interval_days'] ?? PaymentCondition::DAYS_30->days()));

            return $baseDate->copy()->addDays($daysStep * $index);
        }

        $condition = PaymentCondition::tryFrom($mode);
        $daysStep = $condition?->isTerm() ? $condition->days() : PaymentCondition::DAYS_30->days();

        return $baseDate->copy()->addDays($daysStep * $index);
    }
}
