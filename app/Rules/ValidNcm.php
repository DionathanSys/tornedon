<?php

namespace App\Rules;

use App\Services\NcmService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidNcm implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // Se for nullable, deixa passar
        }

        // $ncmService = app(NcmService::class);
        
        // if (!$ncmService->exists($value)) {
        //     $fail('O código NCM informado não é válido ou não foi encontrado na tabela vigente.');
        // }
    }
}
