<?php

namespace Tests\Unit\Services\Commitments;

use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\Pdf\ChapecoCommitmentParser;
use App\Services\Commitments\Pdf\ChapecoPaymentReportParser;
use App\Services\Commitments\Pdf\ChapecoText;
use App\Services\Commitments\Pdf\PdfReadingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChapecoPdfParsersTest extends TestCase
{
    public function test_commitment_extracts_header_not_pre_commitment_and_preserves_money_and_items(): void
    {
        $data = (new ChapecoCommitmentParser)->parse($this->commitment(), '83.021.808/0001-82', '12.345.678/0001-95');
        $this->assertSame('14728', $data->number);
        $this->assertSame(2026, $data->fiscalYear);
        $this->assertSame('2026-06-23', $data->issuedAt);
        $this->assertSame('142186', $data->patrimony);
        $this->assertSame(40000, $data->amountCents);
        $this->assertSame(0, $data->retainedAmountCents);
        $this->assertSame(40000, $data->netAmountCents);
        $this->assertSame([], $data->issues);
        $this->assertCount(3, $data->items);
        $this->assertSame('DESLOCAMENTO', $data->items[1]['complement']);
        $this->assertSame(18000, $data->items[0]['amount_cents']);
        $this->assertSame('12345678000195', $data->toArray()['creditor_document']);
    }

    public function test_missing_and_ambiguous_patrimony_require_review_without_guessing(): void
    {
        foreach (['SEM IDENTIFICAÇÃO' => 'não identificado', 'PAT 1 E PAT 2' => 'Mais de um'] as $replacement => $message) {
            $data = (new ChapecoCommitmentParser)->parse($this->commitment(['PATRIMÔNIO N° 142186' => $replacement]));
            $this->assertNull($data->patrimony);
            $this->assertStringContainsString($message, implode(' ', $data->issues));
        }
    }

    public function test_parties_and_totals_must_be_reviewed_when_they_do_not_match(): void
    {
        $data = (new ChapecoCommitmentParser)->parse($this->commitment(['Valor líquido: R$ 400,00' => 'Valor líquido: R$ 390,00', '180,00' => '170,00']), '11111111000111', '22222222000122');
        $this->assertCount(4, $data->issues);
    }

    #[DataProvider('invalidCommitments')]
    public function test_invalid_commitment_structure_is_rejected(array $replacements): void
    {
        $this->expectException(PdfReadingException::class);
        (new ChapecoCommitmentParser)->parse($this->commitment($replacements));
    }

    public static function invalidCommitments(): array
    {
        return [
            'wrong layout' => [['Nota de Empenho' => 'Outro documento']],
            'missing object' => [['Objeto resumido:' => 'Observação:']],
            'invalid date' => [['23/06/2026' => '31/02/2026']],
            'two commitments' => [['Número: 14728/2026' => "Número: 14728/2026\nNúmero: 14731/2026"]],
            'inconsistent repeated value' => [['Saldo atual: R$ 205.522,34                  Valor deste empenho: R$ 400,00' => 'Saldo atual: R$ 205.522,34 Valor deste empenho: R$ 500,00']],
        ];
    }

    public function test_payment_report_inherits_dates_and_objects_across_pages_and_checks_total_once(): void
    {
        $data = (new ChapecoPaymentReportParser)->parse($this->report(), '83021808000182', 'Empresa Exemplo LTDA');
        $this->assertCount(4, $data->lines);
        $this->assertSame(167000, $data->declaredTotalCents);
        $this->assertSame(167000, $data->extractedTotalCents());
        $this->assertFalse($data->requiresReview());
        $this->assertSame('14732', $data->lines[0]->commitmentNumber);
        $this->assertSame('2026-08-27', $data->lines[1]->paymentDate);
        $this->assertSame('79028', $data->lines[1]->patrimony);
        $this->assertSame(1, $data->lines[1]->pageStart);
        $this->assertSame(2, $data->lines[1]->pageEnd);
        $this->assertStringNotContainsString('Prefeitura', $data->lines[1]->object);
        $this->assertSame('2026-09-09', $data->lines[3]->paymentDate);
        $this->assertSame(2, $data->lines[3]->pageStart);
        $this->assertSame(3, $data->lines[3]->pageEnd);
        $this->assertSame(123000, $data->lines[2]->amountCents);
        $this->assertNull($data->lines[2]->patrimony);
        $this->assertSame('247', $data->lines[0]->fiscalDocuments[0]['number']);
        $this->assertSame('2026-07-13', $data->lines[0]->fiscalDocuments[0]['issued_at']);
    }

    public function test_report_total_differences_and_party_mismatch_block_automatic_processing(): void
    {
        $data = (new ChapecoPaymentReportParser)->parse($this->report(['Total do Credor/Fornecedor:          1.670,00' => 'Total do Credor/Fornecedor: 1.671,00']), '11111111000111', 'Outra Empresa');
        $this->assertTrue($data->requiresReview());
        $this->assertCount(4, $data->issues);
        $this->assertSame(167000, $data->extractedTotalCents());
    }

    public function test_report_without_a_declared_total_requires_review(): void
    {
        $data = (new ChapecoPaymentReportParser)->parse($this->report(['Total do Credor/Fornecedor:          1.670,00' => '']));
        $this->assertNull($data->declaredTotalCents);
        $this->assertTrue($data->requiresReview());
    }

    public function test_multiple_fiscal_documents_are_preserved_for_manual_review(): void
    {
        $data = (new ChapecoPaymentReportParser)->parse($this->report(['Nota Fiscal - 247 - 13/07/2026    190,00' => 'Nota Fiscal - 247 - 13/07/2026 100,00   Nota Fiscal - 248 - 13/07/2026 90,00']));
        $this->assertTrue($data->requiresReview());
        $this->assertCount(2, $data->lines[0]->fiscalDocuments);
        $this->assertCount(1, $data->lines[0]->issues);
        $this->assertSame([], $data->issues);
    }

    public function test_a_missing_fiscal_document_is_not_silently_accepted(): void
    {
        $data = (new ChapecoPaymentReportParser)->parse($this->report(['Nota Fiscal - 247 - 13/07/2026    190,00' => '']));
        $this->assertTrue($data->requiresReview());
        $this->assertCount(2, $data->lines[0]->issues);
    }

    public function test_mixed_issuer_pages_are_rejected(): void
    {
        $pages = $this->report()->pages;
        $pages[1] = str_replace('83.021.808/0001-82', '11.111.111/0001-11', $pages[1]);
        $this->expectExceptionMessage('emissor do relatório muda');
        (new ChapecoPaymentReportParser)->parse(new ExtractedPdf($pages));
    }

    public function test_repeated_payment_is_flagged_even_when_the_total_matches(): void
    {
        $report = $this->report();
        $pages = $report->pages;
        $pages[2] = str_replace('Total do Credor/Fornecedor:', "27/08/2026 14732/2026 Orçamentário 24/06/2026 1001 - Gabinete B: 104 190,00\nObjeto: SERVIÇO PARA CAMINHÃO PAT. 92181\nDocumento Fiscal Valor\nNota Fiscal - 247 - 13/07/2026 190,00\nTotal do Credor/Fornecedor:", $pages[2]);
        $pages[2] = str_replace('1.670,00', '1.860,00', $pages[2]);
        $data = (new ChapecoPaymentReportParser)->parse(new ExtractedPdf($pages));
        $this->assertSame(186000, $data->extractedTotalCents());
        $this->assertSame(186000, $data->declaredTotalCents);
        $this->assertTrue($data->requiresReview());
        $this->assertStringContainsString('Pagamento repetido', implode(' ', $data->issues));
    }

    #[DataProvider('invalidReports')]
    public function test_invalid_report_structure_is_rejected(array $replacements): void
    {
        $this->expectException(PdfReadingException::class);
        (new ChapecoPaymentReportParser)->parse($this->report($replacements));
    }

    public static function invalidReports(): array
    {
        return [
            'missing first payment date' => [['27/08/2026 14732/2026' => '14732/2026']],
            'invalid payment date' => [['27/08/2026 14732/2026' => '31/02/2026 14732/2026']],
            'unsupported type' => [['14732/2026 Orçamentário' => '14732/2026 Extraorçamentário']],
            'invalid money' => [['C/C: 12345 190,00' => 'C/C: 12345 1.90,00']],
            'multiple creditors' => [['Credor/Fornecedor: 999 - EMPRESA EXEMPLO LTDA' => "Credor/Fornecedor: 999 - EMPRESA EXEMPLO LTDA\nCredor/Fornecedor: 998 - OUTRA EMPRESA"]],
            'wrong title' => [['Relação de Pagamentos Efetuados por Credor/Fornecedor' => 'Extrato financeiro']],
        ];
    }

    #[DataProvider('patrimonyFormats')]
    public function test_patrimony_formats_do_not_use_partial_matches(string $text, array $expected): void
    {
        $this->assertSame($expected, ChapecoText::patrimonies($text));
    }

    public static function patrimonyFormats(): array
    {
        return [
            ['VEÍCULO PAT 92181', ['92181']],
            ['PAT. 141140', ['141140']],
            ["PATRIMÔNIO Nº\n153704", ['153704']],
            ['PATRIMONIO N°142013', ['142013']],
            ['PATRIMÕNIO NO 87014', ['87014']],
            ['PATRIMÔNIO -39274', ['39274']],
            ['PAT 92181 E PAT 9218', ['92181', '9218']],
            ['PAT 92181 E PAT 92181', ['92181']],
            ['PAT 12ABC', []],
            ['PAT 12.345', []],
            ['COMPAT 92181', []],
        ];
    }

    private function commitment(array $replacements = []): ExtractedPdf
    {
        return ExtractedPdf::fromText(strtr(file_get_contents(__DIR__.'/../../../Fixtures/Commitments/chapeco-commitment.txt'), $replacements));
    }

    private function report(array $replacements = []): ExtractedPdf
    {
        return ExtractedPdf::fromText(strtr(str_replace("\n---PAGE---\n", "\f", file_get_contents(__DIR__.'/../../../Fixtures/Commitments/chapeco-payments.txt')), $replacements));
    }
}
