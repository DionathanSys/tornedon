<?php

namespace Tests\Feature\Commitments;

use App\Services\Commitments\Pdf\PdfReadingException;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('pdf-temp');
        config(['commitments.pdf.temporary_directory' => Storage::disk('local')->path('pdf-temp')]);
    }

    public function test_extracts_pages_with_poppler_and_removes_private_temporary_copy(): void
    {
        $this->requirePoppler();
        $file = $this->pdf('<p>Primeira pagina: PAT 92181</p><div style="page-break-before: always">Segunda pagina</div>');
        $pdf = app(PdfTextExtractor::class)->extract($file->getPathname());
        $this->assertCount(2, $pdf->pages);
        $this->assertStringContainsString('PAT 92181', $pdf->pages[0]);
        $this->assertStringContainsString('Segunda pagina', $pdf->pages[1]);
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    public function test_rejects_excess_pages_and_removes_temporary_copy(): void
    {
        $this->requirePoppler();
        config(['commitments.pdf.max_pages' => 1]);
        $file = $this->pdf('<p>Uma pagina</p><div style="page-break-before: always">Outra pagina</div>');
        try {
            app(PdfTextExtractor::class)->extract($file->getPathname());
            $this->fail('Deveria rejeitar páginas acima do limite.');
        } catch (PdfReadingException $exception) {
            $this->assertStringContainsString('limite de páginas', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    public function test_rejects_documents_without_text(): void
    {
        $this->requirePoppler();
        $file = $this->pdf('<html><body></body></html>');
        $this->expectExceptionMessage('OCR');
        app(PdfTextExtractor::class)->extract($file->getPathname());
    }

    public function test_rejects_large_output_and_removes_temporary_copy(): void
    {
        $this->requirePoppler();
        config(['commitments.pdf.max_text_bytes' => 1]);
        $file = $this->pdf('<p>Texto maior que o limite permitido</p>');
        try {
            app(PdfTextExtractor::class)->extract($file->getPathname());
            $this->fail('Deveria rejeitar texto acima do limite.');
        } catch (PdfReadingException $exception) {
            $this->assertStringContainsString('limite de extração', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    public function test_missing_binary_has_actionable_error_and_removes_temporary_copy(): void
    {
        config(['commitments.pdf.binary' => '/nonexistent/commitments-pdftotext']);
        $file = $this->pdf('<p>Texto</p>');
        try {
            app(PdfTextExtractor::class)->extract($file->getPathname());
            $this->fail('Deveria informar a dependência ausente.');
        } catch (PdfReadingException $exception) {
            $this->assertStringContainsString('Poppler', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    public function test_non_pdf_files_are_rejected_even_with_pdf_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent('not-pdf.pdf', 'not a PDF');
        try {
            app(PdfTextExtractor::class)->extract($file->getPathname());
            $this->fail('Deveria rejeitar assinatura inválida.');
        } catch (PdfReadingException $exception) {
            $this->assertStringContainsString('não é um PDF válido', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    public function test_input_size_limit_is_checked_before_extracting(): void
    {
        config(['commitments.pdf.max_size_bytes' => 5]);
        $file = UploadedFile::fake()->createWithContent('large.pdf', '%PDF-1.4 too large');
        $this->expectExceptionMessage('tamanho máximo');
        app(PdfTextExtractor::class)->extract($file->getPathname());
    }

    public function test_missing_file_produces_reading_error(): void
    {
        $this->expectExceptionMessage('não encontrado');
        app(PdfTextExtractor::class)->extract(Storage::disk('local')->path('missing.pdf'));
    }

    public function test_corrupted_pdf_has_explicit_error_and_cleans_temporary_copy(): void
    {
        $this->requirePoppler();
        $file = UploadedFile::fake()->createWithContent('corrupt.pdf', "%PDF-1.4\ninvalid");
        try {
            app(PdfTextExtractor::class)->extract($file->getPathname());
            $this->fail('Deveria rejeitar PDF corrompido.');
        } catch (PdfReadingException $exception) {
            $this->assertStringContainsString('protegido ou corrompido', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->files('pdf-temp'));
    }

    private function pdf(string $html): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return UploadedFile::fake()->createWithContent('synthetic.pdf', $pdf->output());
    }

    private function requirePoppler(): void
    {
        if (! (new ExecutableFinder)->find(config('commitments.pdf.binary'))) {
            $this->markTestSkipped('Instale poppler-utils para validar a extração real de PDFs.');
        }
    }
}
