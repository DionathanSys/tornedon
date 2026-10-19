<?php

namespace App\Services\Commitments\Pdf;

use DateTimeImmutable;
use Illuminate\Support\Str;

final class ChapecoText
{
    public const MONEY = '(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}';

    public static function normalized(string $text): string
    {
        return strtoupper(Str::ascii($text));
    }

    public static function compact(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    public static function cents(string $value): int
    {
        if (! preg_match('/^'.self::MONEY.'$/D', $value)) {
            throw new PdfReadingException('Valor monetário inválido no documento: '.$value);
        }
        [$integer, $fraction] = explode(',', str_replace('.', '', $value));
        if (strlen($integer) > 13) {
            throw new PdfReadingException('Valor monetário fora do limite permitido.');
        }

        return (int) $integer * 100 + (int) $fraction;
    }

    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        if (! $date || $date->format('d/m/Y') !== $value) {
            throw new PdfReadingException('Data inválida no documento: '.$value);
        }

        return $date->format('Y-m-d');
    }

    /** @return list<string> */
    public static function patrimonies(string $text): array
    {
        preg_match_all('/\bPAT(?:RIMONIO)?\.?\s*(?:N(?:[O°º.]|RO)?\s*)?[:.\-]?\s*(\d+)(?![A-Z0-9]|[.,]\d)/u', self::normalized($text), $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function issuerDocument(string $text): string
    {
        if (! preg_match('/CNPJ:\s*(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})/u', $text, $match)) {
            throw new PdfReadingException('CNPJ do emissor não identificado no PDF.');
        }

        return preg_replace('/\D/', '', $match[1]);
    }

    public static function assertIssuer(string $text): void
    {
        if (! str_contains(self::normalized($text), 'PREFEITURA MUNICIPAL DE CHAPECO')) {
            throw new PdfReadingException('Layout não reconhecido: esperado documento da Prefeitura Municipal de Chapecó.');
        }
    }
}
