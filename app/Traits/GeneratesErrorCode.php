<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait GeneratesErrorCode
{
    /**
     * Gera um código único de erro para referência em logs
     * Formato: ERR-YYYYMMDD-HHMMSS-XXXXX
     * 
     * @return string
     */
    protected function generateErrorCode(): string
    {
        $timestamp = now()->format('Ymd-His');
        $random = strtoupper(Str::random(5));
        
        return "ERR-{$timestamp}-{$random}";
    }
}
