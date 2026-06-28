<?php

namespace App\Services\Cnpj;

class CnpjDocument
{
    public function sanitize(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj) ?? '';
    }

    public function isValid(string $cnpj): bool
    {
        $cnpj = $this->sanitize($cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $calcDigit = function (string $digits, int $length): int {
            $weights = $length === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weights[$i];
            }

            $remainder = $sum % 11;

            return $remainder < 2 ? 0 : 11 - $remainder;
        };

        $firstDigit = $calcDigit($cnpj, 12);
        $secondDigit = $calcDigit($cnpj, 13);

        return (int) $cnpj[12] === $firstDigit
            && (int) $cnpj[13] === $secondDigit;
    }
}
