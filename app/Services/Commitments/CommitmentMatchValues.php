<?php

namespace App\Services\Commitments;

use App\Services\Commitments\Pdf\ChapecoText;
use Illuminate\Validation\ValidationException;

final class CommitmentMatchValues
{
    public static function cents(string|int|float|null $amount): int
    {
        $amount = (string) ($amount ?? '0');
        if (! preg_match('/^(\d{1,11})(?:\.(\d{1,2}))?$/D', $amount, $parts)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor positivo com até duas casas decimais.']);
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function identifier(string $value): string
    {
        return ltrim($value, '0') ?: '0';
    }

    public static function equipmentPatrimonies(string $name): array
    {
        return array_values(array_unique(array_map(self::identifier(...), ChapecoText::patrimonies($name))));
    }
}
