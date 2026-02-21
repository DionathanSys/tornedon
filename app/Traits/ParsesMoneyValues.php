<?php

namespace App\Traits;

trait ParsesMoneyValues
{
    protected static function parseMoneyValue($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        // Se já for numérico, retorna
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Converte formato brasileiro (1.234,56) para float
        $value = str_replace('.', '', $value); // Remove separador de milhares
        $value = str_replace(',', '.', $value); // Converte vírgula decimal para ponto
        
        return (float) $value;
    }
}
