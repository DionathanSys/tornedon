<?php

namespace App\Enum\Financial;

enum ChartAccountType: string
{
    case REVENUE = 'revenue';
    case DEDUCTION = 'deduction';
    case COST = 'cost';
    case EXPENSE = 'expense';
    case FINANCIAL_RESULT = 'financial_result';
    case OTHER = 'other';

    public function description(): string
    {
        return match ($this) {
            self::REVENUE => 'Receita',
            self::DEDUCTION => 'Dedução',
            self::COST => 'Custo',
            self::EXPENSE => 'Despesa',
            self::FINANCIAL_RESULT => 'Resultado Financeiro',
            self::OTHER => 'Outros',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
