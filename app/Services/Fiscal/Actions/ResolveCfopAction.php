<?php

namespace App\Services\Fiscal\Actions;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use Illuminate\Support\Facades\Log;

/**
 * Ajusta o primeiro dígito do CFOP com base na UF do emitente vs destinatário.
 *
 * Regras:
 *   Saída interna      → 5xxx
 *   Saída interestadual → 6xxx
 *   Entrada interna     → 1xxx
 *   Entrada interestadual → 2xxx
 *   Exportação          → 7xxx (não sofre auto-swap)
 */
class ResolveCfopAction
{
    /**
     * Dado um CFOP base configurado e o contexto fiscal,
     * retorna o CFOP com o primeiro dígito correto.
     */
    public function execute(string $baseCfop, FiscalContextDTO $context): string
    {
        if (strlen($baseCfop) < 4) {
            return $baseCfop;
        }

        $firstDigit = (int) $baseCfop[0];
        $suffix = substr($baseCfop, 1); // últimos 3 dígitos

        // Exportação (7xxx) — não sofre auto-swap
        if ($firstDigit === 7) {
            return $baseCfop;
        }

        $resolved = $baseCfop;

        if ($context->isInterestadual()) {
            // Operação interestadual: 5→6, 1→2
            $resolved = match ($firstDigit) {
                5 => "6{$suffix}",
                1 => "2{$suffix}",
                default => $baseCfop,
            };
        } else {
            // Operação interna: 6→5, 2→1
            $resolved = match ($firstDigit) {
                6 => "5{$suffix}",
                2 => "1{$suffix}",
                default => $baseCfop,
            };
        }

        if ($resolved !== $baseCfop) {
            Log::debug("ResolveCfopAction: CFOP ajustado de '{$baseCfop}' para '{$resolved}'", [
                'issuer_uf' => $context->issuerUf,
                'recipient_uf' => $context->recipientUf,
                'is_interestadual' => $context->isInterestadual(),
            ]);
        }

        Log::debug('ResolveCfopAction: CFOP resolvido', [
            'base_cfop' => $baseCfop,
            'resolved' => $resolved,
            'issuer_uf' => $context->issuerUf,
            'recipient_uf' => $context->recipientUf,
            'is_interestadual' => $context->isInterestadual(),
        ]);

        return $resolved;
    }
}
