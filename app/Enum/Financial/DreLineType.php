<?php

namespace App\Enum\Financial;

enum DreLineType: string
{
    case ACCOUNT_GROUP = 'account_group';
    case SUBTOTAL = 'subtotal';
    case FORMULA = 'formula';
    case HEADER = 'header';
    case SEPARATOR = 'separator';

    public function description(): string
    {
        return match ($this) {
            self::ACCOUNT_GROUP => 'Grupo de contas',
            self::SUBTOTAL => 'Subtotal',
            self::FORMULA => 'Fórmula',
            self::HEADER => 'Cabeçalho',
            self::SEPARATOR => 'Separador',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
