<?php

namespace App\Services\Commitments\DTO;

use App\Services\Commitments\Pdf\PdfReadingException;

final readonly class ExtractedPdf
{
    /** @param list<string> $pages */
    public function __construct(public array $pages)
    {
        if ($pages === [] || trim(implode('', $pages)) === '') {
            throw new PdfReadingException('O PDF não possui texto extraível. Verifique se é uma digitalização que exige OCR.');
        }
    }

    public static function fromText(string $text): self
    {
        $pages = explode("\f", str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text));
        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return new self($pages);
    }

    public function text(): string
    {
        return implode("\f", $this->pages);
    }
}
