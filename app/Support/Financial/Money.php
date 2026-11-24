<?php

namespace App\Support\Financial;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Exact BRL arithmetic. Floats are accepted only at the legacy API boundary. */
final class Money
{
    public static function cents(mixed $value, bool $strict = false): int
    {
        $text = trim((string) ($value ?? '0'));
        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $parts)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor monetário decimal válido.']);
        }
        $fraction = $parts[3] ?? '';
        if (strlen(ltrim($parts[2], '0')) > 12 || ($strict && trim(substr($fraction, 2), '0') !== '')) {
            throw ValidationException::withMessages(['amount' => 'Use até duas casas decimais e um valor dentro do limite financeiro.']);
        }
        $cents = ((int) $parts[2]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        if (! $strict && (int) ($fraction[2] ?? '0') >= 5) {
            $cents++;
        }

        return ($parts[1] === '-' ? -1 : 1) * $cents;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Read the actual storage scale; payable payments are decimal reais, not cents. */
    public static function stored(Model $model, string $field = 'amount'): int
    {
        $raw = $model->getRawOriginal($field) ?? 0;

        return ($model->getCasts()[$field] ?? null) === MoneyCast::class
            ? (int) $raw
            : self::cents($raw);
    }

    public static function sum(iterable $models, string $field): int
    {
        $total = 0;
        foreach ($models as $model) {
            $total += self::stored($model, $field);
        }

        return $total;
    }

    /** Rounded proportion without multiplying two large monetary integers into a float. */
    public static function proportion(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }
        $product = '';
        $carry = 0;
        foreach (array_reverse(str_split((string) abs($amount))) as $digit) {
            $part = (int) $digit * abs($numerator) + $carry;
            $product = ($part % 10).$product;
            $carry = intdiv($part, 10);
        }
        $product = ($carry > 0 ? (string) $carry : '').$product;
        $quotient = '';
        $remainder = 0;
        foreach (str_split($product) as $digit) {
            $part = $remainder * 10 + (int) $digit;
            $quotient .= intdiv($part, $denominator);
            $remainder = $part % $denominator;
        }

        return (($amount < 0) !== ($numerator < 0) ? -1 : 1) * ((int) $quotient + ($remainder * 2 >= $denominator ? 1 : 0));
    }
}
