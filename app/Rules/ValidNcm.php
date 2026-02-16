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
            return;
        }

        $ncmService = app(NcmService::class);

        // Se a tabela estiver vazia, tenta importar antes de validar
        if (!$ncmService->hasData()) {
            if (!$ncmService->ensureDataLoaded()) {
                // Não conseguiu carregar — não bloqueia o usuário
                return;
            }
        }

        if (!$ncmService->exists($value)) {
            $fail('O código NCM informado não é válido ou não foi encontrado na tabela vigente.');
        }
    }
}
