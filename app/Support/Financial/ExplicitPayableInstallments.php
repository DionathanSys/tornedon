<?php

namespace App\Support\Financial;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExplicitPayableInstallments
{
    public static function build(array $data): array
    {
        $rows = Validator::make($data, [
            'installments' => ['required', 'array', 'min:1', 'max:999'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.due_amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'installments.*.description' => ['nullable', 'string', 'max:255'],
            'installments.*.notes' => ['nullable', 'string'],
        ])->validate()['installments'];

        $total = array_sum(array_map(fn (array $row): int => (int) round((float) $row['due_amount'] * 100), $rows));
        if ($total !== (int) round((float) $data['due_amount'] * 100)) {
            throw ValidationException::withMessages(['installments' => 'A soma das parcelas deve ser igual ao valor da conta.']);
        }

        unset($data['installments']);

        return array_map(fn (array $row, int $index): array => [
            ...$data,
            ...$row,
            'sequence_number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        ], array_values($rows), array_keys(array_values($rows)));
    }
}
