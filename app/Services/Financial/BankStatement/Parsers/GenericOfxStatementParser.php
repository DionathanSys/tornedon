<?php

namespace App\Services\Financial\BankStatement\Parsers;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Domain\DTO\Financial\OfxTransactionDTO;
use App\Enum\Financial\CashMovementDirection;
use App\Services\Financial\BankStatement\Contracts\OfxStatementParserInterface;
use Illuminate\Validation\ValidationException;

final class GenericOfxStatementParser implements OfxStatementParserInterface
{
    public function parse(string $contents): array
    {
        $contents = $this->normalizeEncoding($contents);
        $normalized = trim(str_replace("\r", '', $contents));

        if ($normalized === '' || stripos($normalized, '<OFX>') === false) {
            throw ValidationException::withMessages([
                'file' => ['Arquivo OFX invalido ou vazio.'],
            ]);
        }

        $transactions = $this->parseTransactions($normalized);

        if ($transactions === []) {
            throw ValidationException::withMessages([
                'file' => ['Nenhum lancamento OFX foi encontrado no arquivo informado.'],
            ]);
        }

        return [
            'header' => new OfxStatementHeaderDTO(
                bankId: $this->extractTag($normalized, 'BANKID'),
                branchId: $this->extractTag($normalized, 'BRANCHID'),
                accountId: $this->extractTag($normalized, 'ACCTID'),
                accountType: $this->extractTag($normalized, 'ACCTTYPE'),
                currency: $this->extractTag($normalized, 'CURDEF'),
                statementStart: $this->parseOfxDate($this->extractTag($normalized, 'DTSTART')),
                statementEnd: $this->parseOfxDate($this->extractTag($normalized, 'DTEND')),
                ledgerBalance: $this->parseAmount($this->extractTag($normalized, 'BALAMT')),
            ),
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<int, OfxTransactionDTO>
     */
    private function parseTransactions(string $contents): array
    {
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $contents, $matches);

        $transactions = [];

        foreach ($matches[1] ?? [] as $block) {
            $signedAmount = $this->parseAmount($this->extractTag($block, 'TRNAMT'));
            $transactionDate = $this->parseOfxDate($this->extractTag($block, 'DTPOSTED'));

            if ($signedAmount === null || $transactionDate === null) {
                continue;
            }

            $direction = $signedAmount >= 0
                ? CashMovementDirection::INFLOW
                : CashMovementDirection::OUTFLOW;

            $description = $this->firstFilled([
                $this->extractTag($block, 'MEMO'),
                $this->extractTag($block, 'NAME'),
                $this->extractTag($block, 'PAYEE'),
            ]) ?? 'Lancamento OFX';

            $transactions[] = new OfxTransactionDTO(
                transactionDate: $transactionDate,
                amount: round(abs($signedAmount), 2),
                signedAmount: round($signedAmount, 2),
                direction: $direction,
                description: trim($description),
                externalId: $this->extractTag($block, 'FITID'),
                documentNumber: $this->firstFilled([
                    $this->extractTag($block, 'CHECKNUM'),
                    $this->extractTag($block, 'REFNUM'),
                ]),
                transactionType: $this->extractTag($block, 'TRNTYPE'),
                raw: [
                    'trntype' => $this->extractTag($block, 'TRNTYPE'),
                    'dtposted' => $this->extractTag($block, 'DTPOSTED'),
                    'trnamt' => $this->extractTag($block, 'TRNAMT'),
                    'fitid' => $this->extractTag($block, 'FITID'),
                    'checknum' => $this->extractTag($block, 'CHECKNUM'),
                    'name' => $this->extractTag($block, 'NAME'),
                    'memo' => $this->extractTag($block, 'MEMO'),
                ],
            );
        }

        return $transactions;
    }

    private function extractTag(string $source, string $tag): ?string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>\s*([^<\n\r]+)/i', $source, $matches) !== 1) {
            return null;
        }

        $value = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $value !== '' ? $value : null;
    }

    private function normalizeEncoding(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $declaredEncoding = $this->declaredEncoding($contents);

        foreach (array_filter([$declaredEncoding, 'Windows-1252', 'ISO-8859-1']) as $encoding) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', $encoding);

            if (mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private function declaredEncoding(string $contents): ?string
    {
        if (preg_match('/^CHARSET:\s*([^\r\n]+)/mi', $contents, $matches) !== 1) {
            return null;
        }

        return match (strtoupper(trim($matches[1]))) {
            '1252', 'WINDOWS-1252', 'CP1252' => 'Windows-1252',
            'ISO-8859-1', 'ISO8859-1', 'LATIN1' => 'ISO-8859-1',
            'UTF-8', 'UTF8' => 'UTF-8',
            default => null,
        };
    }

    private function parseOfxDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
    }

    private function parseAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', $value) ?? '');

        return $normalized !== '' ? round((float) $normalized, 2) : null;
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
