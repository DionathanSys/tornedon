<?php

namespace Tests\Feature\Commitments;

use App\Services\Commitments\DTO\ExtractedPdf;
use App\Services\Commitments\Pdf\PdfReadingException;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DiagnoseCommitmentPdfCommandTest extends TestCase
{
    public function test_json_output_contains_commitment_data_and_continues_after_an_invalid_file(): void
    {
        $this->mock(PdfTextExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->with('bad.pdf')->andThrow(new PdfReadingException('PDF inválido.'));
            $mock->shouldReceive('extract')->once()->with('good.pdf')->andReturn(ExtractedPdf::fromText(file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-commitment.txt'))));
        });
        $status = Artisan::call('commitments:diagnose-pdf', ['type' => 'commitments', 'files' => ['bad.pdf', 'good.pdf'], '--json' => true]);
        $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $status);
        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('processed', $results[1]['status']);
        $this->assertSame('14728', $results[1]['data']['number']);
        $this->assertSame(40000, $results[1]['data']['amount_cents']);
    }

    public function test_json_report_output_contains_integrity_totals_and_payment_dates(): void
    {
        $this->mock(PdfTextExtractor::class, function ($mock): void {
            $text = str_replace("\n---PAGE---\n", "\f", file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-payments.txt')));
            $mock->shouldReceive('extract')->once()->with('report.pdf')->andReturn(ExtractedPdf::fromText($text));
        });
        $status = Artisan::call('commitments:diagnose-pdf', ['type' => 'payments', 'files' => ['report.pdf'], '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(0, $status);
        $this->assertSame(4, $result['data']['line_count']);
        $this->assertSame(167000, $result['data']['declared_total_cents']);
        $this->assertSame(167000, $result['data']['extracted_total_cents']);
        $this->assertSame('2026-09-09', $result['data']['lines'][3]['payment_date']);
    }

    public function test_mismatching_party_returns_review_status_and_unsuccessful_exit(): void
    {
        $this->mock(PdfTextExtractor::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn(ExtractedPdf::fromText(file_get_contents(base_path('tests/Fixtures/Commitments/chapeco-commitment.txt'))));
        });
        $status = Artisan::call('commitments:diagnose-pdf', ['type' => 'commitments', 'files' => ['good.pdf'], '--issuer-document' => '11111111000111', '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(1, $status);
        $this->assertSame('needs_review', $result['status']);
        $this->assertCount(1, $result['data']['issues']);
    }

    public function test_invalid_type_does_not_attempt_extraction(): void
    {
        $this->mock(PdfTextExtractor::class)->shouldNotReceive('extract');
        $this->artisan('commitments:diagnose-pdf', ['type' => 'invalid', 'files' => ['file.pdf']])
            ->expectsOutput('Tipo inválido. Utilize commitments ou payments.')
            ->assertExitCode(2);
    }
}
