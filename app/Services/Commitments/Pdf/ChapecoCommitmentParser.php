<?php

namespace App\Services\Commitments\Pdf;

use App\Services\Commitments\DTO\CommitmentData;
use App\Services\Commitments\DTO\ExtractedPdf;

class ChapecoCommitmentParser
{
    public const VERSION = 'chapeco-commitment-v1';

    public function parse(ExtractedPdf $pdf, ?string $expectedIssuerDocument = null, ?string $expectedCreditorDocument = null): CommitmentData
    {
        $text = $pdf->text();
        ChapecoText::assertIssuer($text);
        if (! str_contains(ChapecoText::normalized($text), 'NOTA DE EMPENHO')) {
            throw new PdfReadingException('Layout não reconhecido: esperado título Nota de Empenho.');
        }
        preg_match_all('/\bN[uú]mero:\s*(\d+)\/(\d{4})/u', $text, $numbers, PREG_SET_ORDER);
        if (count($numbers) !== 1) {
            throw new PdfReadingException('Esperado exatamente um número de empenho no PDF.');
        }
        $number = $numbers[0];
        $issuedAt = ChapecoText::date($this->required('/Emiss[aã]o:\s*(\d{2}\/\d{2}\/\d{4})/u', $text, 'emissão'));
        $object = ChapecoText::compact($this->required('/Objeto resumido:\s*(.*?)\s*Itens do Empenho/su', $text, 'objeto resumido'));
        $patrimonies = ChapecoText::patrimonies($object);
        $issues = [];
        if (count($patrimonies) !== 1) {
            $issues[] = count($patrimonies) === 0 ? 'Patrimônio não identificado no objeto.' : 'Mais de um patrimônio identificado no objeto.';
        }

        preg_match_all('/Valor deste empenho:\s*R\$\s*('.ChapecoText::MONEY.')/u', $text, $amounts);
        $amounts = array_values(array_unique(array_map(ChapecoText::cents(...), $amounts[1])));
        if (count($amounts) !== 1) {
            throw new PdfReadingException('Valor do empenho ausente ou divergente entre as ocorrências do documento.');
        }
        $retained = ChapecoText::cents($this->required('/Total retido:\s*R\$\s*('.ChapecoText::MONEY.')/u', $text, 'valor retido'));
        $net = ChapecoText::cents($this->required('/Valor l[ií]quido:\s*R\$\s*('.ChapecoText::MONEY.')/u', $text, 'valor líquido'));
        if ($amounts[0] <= 0 || $net !== $amounts[0] - $retained) {
            $issues[] = 'Valor bruto, retido e líquido não correspondem a um empenho positivo consistente.';
        }
        if (! preg_match('/^\s*Credor:\s*(\d+)\s*-\s*(.*?)\s+CNPJ:\s*(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})/mu', $text, $creditor)) {
            throw new PdfReadingException('Credor e CNPJ do credor não identificados.');
        }
        $issuerDocument = ChapecoText::issuerDocument($text);
        $creditorDocument = preg_replace('/\D/', '', $creditor[3]);
        if ($expectedIssuerDocument !== null && preg_replace('/\D/', '', $expectedIssuerDocument) !== $issuerDocument) {
            $issues[] = 'CNPJ do emissor não corresponde ao cliente selecionado.';
        }
        if ($expectedCreditorDocument !== null && preg_replace('/\D/', '', $expectedCreditorDocument) !== $creditorDocument) {
            $issues[] = 'CNPJ do credor não corresponde à empresa selecionada.';
        }

        $items = $this->items($text);
        if ($items === []) {
            $issues[] = 'Itens do empenho não identificados.';
        } elseif (array_sum(array_column($items, 'amount_cents')) !== $amounts[0]) {
            $issues[] = 'A soma dos itens difere do valor do empenho.';
        }

        return new CommitmentData(
            number: ltrim($number[1], '0') ?: '0',
            fiscalYear: (int) $number[2],
            issuedAt: $issuedAt,
            patrimony: count($patrimonies) === 1 ? $patrimonies[0] : null,
            object: $object,
            amountCents: $amounts[0],
            retainedAmountCents: $retained,
            netAmountCents: $net,
            issuerDocument: $issuerDocument,
            creditorDocument: $creditorDocument,
            creditorName: trim($creditor[2]),
            originalNumber: $number[1].'/'.$number[2],
            items: $items,
            issues: $issues,
        );
    }

    private function required(string $pattern, string $text, string $field): string
    {
        if (! preg_match($pattern, $text, $match) || trim($match[1]) === '') {
            throw new PdfReadingException('Campo obrigatório não identificado: '.$field.'.');
        }

        return $match[1];
    }

    private function items(string $text): array
    {
        $items = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+(?:\.\d{3})*,\d+)\s+(\S+)\s+(\d+)\s+(.+?)\s+([\d.]+,\d+)\s+('.ChapecoText::MONEY.')\s*$/u', $line, $match)) {
                $items[] = ['position' => (int) $match[1], 'quantity' => $match[2], 'unit' => $match[3], 'code' => $match[4], 'description' => trim($match[5]), 'unit_value' => $match[6], 'amount_cents' => ChapecoText::cents($match[7]), 'complement' => null];
            } elseif ($items !== [] && preg_match('/Complemento do item:\s*(.+)/u', $line, $match)) {
                $items[array_key_last($items)]['complement'] = trim($match[1]);
            }
        }

        return $items;
    }
}
