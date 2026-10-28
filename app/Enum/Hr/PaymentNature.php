<?php

namespace App\Enum\Hr;

enum PaymentNature: string
{
    case SALARY = 'salary';
    case PRO_LABORE = 'pro_labore';
    case PROFIT = 'profit';
    case ADVANCE = 'advance';

    public static function options(): array
    {
        return ['salary' => 'Salário', 'pro_labore' => 'Pró-labore', 'profit' => 'Distribuição de lucros', 'advance' => 'Adiantamento'];
    }
}
