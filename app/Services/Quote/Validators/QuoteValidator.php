<?php

namespace App\Services\Quote\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QuoteValidator
{
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'partner_id' => 'required|exists:partners,id',
            'company_id' => 'required|exists:companies,id',
            'description' => 'nullable|string',
            'valid_until' => 'nullable|date|after:today',
            'observations' => 'nullable|string',
            'customer_observations' => 'nullable|string',
        ], [
            'partner_id.required' => 'Cliente é obrigatório',
            'partner_id.exists' => 'Cliente não encontrado',
            'company_id.required' => 'Empresa é obrigatória',
            'company_id.exists' => 'Empresa não encontrada',
            'valid_until.after' => 'Data de validade deve ser futura',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    public static function validateApproval(array $data): void
    {
        $validator = Validator::make($data, [
            'status' => 'required|in:sent',
        ], [
            'status.in' => 'Apenas orçamentos enviados podem ser aprovados',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
