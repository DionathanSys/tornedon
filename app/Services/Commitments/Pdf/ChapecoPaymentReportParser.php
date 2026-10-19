<?php

namespace App\Services\Commitments\Pdf;

use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\DTO\PaymentReportData;
use App\Services\Commitments\DTO\PaymentReportLineData;

class ChapecoPaymentReportParser
{
    public const VERSION = 'chapeco-payments-v1';

    public function parse(ExtractedPdf $pdf, ?string $expectedIssuerDocument = null, ?string $expectedCreditorName = null): PaymentReportData
    {
        $text = $pdf->text();
        ChapecoText::assertIssuer($text);
        $issuerDocument = ChapecoText::issuerDocument($text);
        preg_match_all('/^\s*Credor\/Fornecedor:\s*(\d+)\s*-\s*(.+)$/mu', $text, $creditors, PREG_SET_ORDER);
        if (count($creditors) !== 1) {
            throw new PdfReadingException('Esperado relatório de um único credor/fornecedor.');
        }

        $lines = [];
        $issues = [];
        $current = null;
        $paymentDate = null;
        $declaredTotals = [];
        $summaryTotals = [];
        foreach ($pdf->pages as $pageIndex => $page) {
            ChapecoText::assertIssuer($page);
            if (ChapecoText::issuerDocument($page) !== $issuerDocument) {
                throw new PdfReadingException('O emissor do relatório muda entre as páginas.');
            }
            $normalized = ChapecoText::normalized($page);
            if (! str_contains($normalized, 'RELACAO DE PAGAMENTOS EFETUADOS POR CREDOR/FORNECEDOR')) {
                throw new PdfReadingException('Cabeçalho do relatório de pagamentos não identificado na página '.($pageIndex + 1).'.');
            }
            // Remove only the repeated header; the first remaining line may continue a prior record.
            if (! preg_match('/^\s*Data\s+N[uú]mero\s+Tipo[^\n]*\n(.*)$/msu', $page, $body)) {
                throw new PdfReadingException('Colunas do relatório não identificadas na página '.($pageIndex + 1).'.');
            }

            foreach (explode("\n", $body[1]) as $raw) {
                $line = trim($raw);
                if ($line === '') {
                    continue;
                }
                $normalizedLine = ChapecoText::normalized($line);
                if (str_starts_with($normalizedLine, 'UNIDADE GESTORA:') || str_starts_with($normalizedLine, 'CREDOR/FORNECEDOR:')) {
                    continue;
                }
                if (preg_match('/TOTAL (?:DO CREDOR\/FORNECEDOR|DA UNIDADE GESTORA|GERAL):\s*('.ChapecoText::MONEY.')$/', $normalizedLine, $total)) {
                    if (str_starts_with($normalizedLine, 'TOTAL DO CREDOR/FORNECEDOR:')) {
                        $declaredTotals[] = ChapecoText::cents($total[1]);
                    } else {
                        $summaryTotals[] = ChapecoText::cents($total[1]);
                    }

                    continue;
                }

                if (preg_match('/^(?:(\d{2}\/\d{2}\/\d{4})\s+)?(\d+)\/(\d{4})\s+ORCAMENTARIO\s+(\d{2}\/\d{2}\/\d{4})\s+.+?\s+('.ChapecoText::MONEY.')$/', $normalizedLine, $row)) {
                    if ($current !== null) {
                        $lines[] = $this->finish($current, count($lines) + 1);
                    }
                    if ($row[1] !== '') {
                        $paymentDate = ChapecoText::date($row[1]);
                    }
                    if ($paymentDate === null) {
                        throw new PdfReadingException('Data do primeiro grupo de pagamentos não identificada.');
                    }
                    $current = ['number' => $row[2], 'year' => (int) $row[3], 'issued_at' => ChapecoText::date($row[4]), 'payment_date' => $paymentDate, 'amount' => ChapecoText::cents($row[5]), 'page_start' => $pageIndex + 1, 'page_end' => $pageIndex + 1, 'object_lines' => [], 'documents' => [], 'raw' => [$raw], 'reading_object' => false];

                    continue;
                }

                if (preg_match('/^(?:\d{2}\/\d{2}\/\d{4}\s+)?\d+\/\d{4}\s+/', $normalizedLine)) {
                    throw new PdfReadingException('Registro de pagamento não reconhecido na página '.($pageIndex + 1).': confira tipo e valor.');
                }
                if ($current === null) {
                    throw new PdfReadingException('Conteúdo inesperado antes do primeiro pagamento na página '.($pageIndex + 1).'.');
                }
                if (preg_match('/^-\d+$/', $line)) {
                    continue; // Wrapped bank account check digit, not part of the object.
                }
                if (str_starts_with($normalizedLine, 'DOCUMENTO FISCAL')) {
                    $current['reading_object'] = false;

                    continue;
                }
                if (preg_match_all('/NOTA FISCAL\s*-\s*(\d+)\s*-\s*(\d{2}\/\d{2}\/\d{4})\s+('.ChapecoText::MONEY.')/', $normalizedLine, $documents, PREG_SET_ORDER)) {
                    foreach ($documents as $document) {
                        $current['documents'][] = ['number' => $document[1], 'issued_at' => ChapecoText::date($document[2]), 'amount_cents' => ChapecoText::cents($document[3])];
                    }
                    $current['reading_object'] = false;
                } elseif (str_starts_with($normalizedLine, 'OBJETO:')) {
                    $current['reading_object'] = true;
                    $current['object_lines'][] = trim(substr($line, strlen('Objeto:')));
                } elseif ($current['reading_object']) {
                    $current['object_lines'][] = $line;
                } else {
                    throw new PdfReadingException('Conteúdo do pagamento não reconhecido na página '.($pageIndex + 1).'.');
                }
                $current['page_end'] = $pageIndex + 1;
                $current['raw'][] = $raw;
            }
        }

        if ($current !== null) {
            $lines[] = $this->finish($current, count($lines) + 1);
        }
        if ($lines === []) {
            throw new PdfReadingException('Nenhum pagamento identificado no relatório.');
        }
        $identities = [];
        foreach ($lines as $line) {
            $identity = json_encode([$line->commitmentNumber, $line->fiscalYear, $line->paymentDate, $line->amountCents, $line->fiscalDocuments], JSON_THROW_ON_ERROR);
            if (isset($identities[$identity])) {
                $issues[] = 'Pagamento repetido no relatório: '.$line->commitmentNumber.'/'.$line->fiscalYear.'.';
            }
            $identities[$identity] = true;
        }
        $declared = count($declaredTotals) === 1 ? $declaredTotals[0] : null;
        $extracted = array_sum(array_map(fn (PaymentReportLineData $line): int => $line->amountCents, $lines));
        if ($declared === null) {
            $issues[] = 'Total do credor ausente ou ambíguo.';
        } elseif ($declared !== $extracted) {
            $issues[] = 'A soma dos pagamentos extraídos difere do total declarado do credor.';
        }
        if ($declared !== null && array_filter($summaryTotals, fn (int $total): bool => $total !== $declared) !== []) {
            $issues[] = 'Totais da unidade gestora/geral divergem do total do credor.';
        }
        if ($expectedIssuerDocument !== null && preg_replace('/\D/', '', $expectedIssuerDocument) !== $issuerDocument) {
            $issues[] = 'CNPJ do emissor não corresponde ao cliente selecionado.';
        }
        if ($expectedCreditorName !== null && ChapecoText::normalized(ChapecoText::compact($expectedCreditorName)) !== ChapecoText::normalized(ChapecoText::compact($creditors[0][2]))) {
            $issues[] = 'Nome do credor não corresponde à empresa selecionada.';
        }

        return new PaymentReportData($issuerDocument, trim($creditors[0][2]), $creditors[0][1], $lines, $declared, $issues);
    }

    private function finish(array $row, int $position): PaymentReportLineData
    {
        $object = ChapecoText::compact(implode(' ', $row['object_lines']));
        $patrimonies = ChapecoText::patrimonies($object);
        $issues = [];
        if ($object === '') {
            $issues[] = 'Objeto do pagamento não identificado.';
        }
        if (count($patrimonies) > 1) {
            $issues[] = 'Mais de um patrimônio identificado no objeto.';
        }
        if (count($row['documents']) !== 1) {
            $issues[] = 'Esperada uma única nota fiscal por pagamento; revisar documentos fiscais.';
        }
        if (array_sum(array_column($row['documents'], 'amount_cents')) !== $row['amount']) {
            $issues[] = 'Valor dos documentos fiscais difere do valor pago.';
        }
        if ($row['amount'] <= 0) {
            $issues[] = 'Valor do pagamento deve ser positivo.';
        }

        return new PaymentReportLineData(
            position: $position,
            commitmentNumber: ltrim($row['number'], '0') ?: '0',
            fiscalYear: $row['year'],
            paymentDate: $row['payment_date'],
            issuedAt: $row['issued_at'],
            amountCents: $row['amount'],
            patrimony: count($patrimonies) === 1 ? $patrimonies[0] : null,
            object: $object,
            fiscalDocuments: $row['documents'],
            pageStart: $row['page_start'],
            pageEnd: $row['page_end'],
            originalText: implode("\n", $row['raw']),
            issues: $issues,
        );
    }
}
