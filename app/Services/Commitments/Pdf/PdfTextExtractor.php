<?php

namespace App\Services\Commitments\Pdf;

use App\Services\Commitments\DTO\ExtractedPdf;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    public function extract(string $path): ExtractedPdf
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new PdfReadingException('Arquivo PDF não encontrado ou sem permissão de leitura.');
        }

        $maxSize = (int) config('commitments.pdf.max_size_bytes');
        if (filesize($path) > $maxSize) {
            throw new PdfReadingException('O PDF excede o tamanho máximo configurado.');
        }

        $directory = config('commitments.pdf.temporary_directory');
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new PdfReadingException('Diretório temporário do leitor de PDFs indisponível.');
        }
        $temporary = tempnam($directory, 'commitment_');
        if ($temporary === false) {
            throw new PdfReadingException('Não foi possível preparar o arquivo temporário do PDF.');
        }

        try {
            chmod($temporary, 0600);
            $this->copyBounded($path, $temporary, $maxSize);
            $handle = fopen($temporary, 'rb');
            try {
                $signature = $handle ? fread($handle, 5) : false;
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            if ($signature !== '%PDF-') {
                throw new PdfReadingException('O arquivo informado não é um PDF válido.');
            }

            $maxPages = max(1, (int) config('commitments.pdf.max_pages'));
            $process = new Process([
                config('commitments.pdf.binary'), '-layout', '-enc', 'UTF-8',
                '-f', '1', '-l', (string) ($maxPages + 1), $temporary, '-',
            ]);
            $process->setTimeout((float) config('commitments.pdf.timeout_seconds'));
            $outputBytes = 0;
            $process->run(function (string $type, string $buffer) use (&$outputBytes): void {
                $outputBytes += strlen($buffer);
                if ($outputBytes > (int) config('commitments.pdf.max_text_bytes')) {
                    throw new PdfReadingException('O texto do PDF excede o limite de extração configurado.');
                }
            });
            if (! $process->isSuccessful()) {
                throw new PdfReadingException('Não foi possível ler o PDF. Verifique o Poppler (pdftotext) e se o documento está protegido ou corrompido.');
            }

            $document = ExtractedPdf::fromText($process->getOutput());
            if (count($document->pages) > $maxPages) {
                throw new PdfReadingException('O PDF excede o limite de páginas configurado.');
            }

            return $document;
        } catch (ExceptionInterface $exception) {
            throw new PdfReadingException('Falha ao executar pdftotext. Verifique a instalação do Poppler e o tempo limite configurado.', previous: $exception);
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->stop(0);
            }
            unlink($temporary);
        }
    }

    private function copyBounded(string $source, string $target, int $maxSize): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'wb');
        try {
            $copied = $input && $output ? stream_copy_to_stream($input, $output, $maxSize + 1) : false;
            if ($copied === false || $copied > $maxSize) {
                throw new PdfReadingException('Não foi possível preparar o PDF dentro do tamanho permitido.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
