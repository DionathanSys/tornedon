<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalDocumentCorrectionLetterController extends Controller
{
    public function download(Request $request, FiscalDocument $fiscalDocument, int $sequencial, string $type): StreamedResponse
    {
        abort_unless($request->user()?->belongsToCompany((int) $fiscalDocument->company_id), 403);
        abort_unless($fiscalDocument->isNfe(), 404);
        abort_unless(in_array($type, ['pdf', 'xml'], true), 404);

        $correction = collect(data_get($fiscalDocument->nfe_payload, 'correcoes', []))
            ->first(fn (mixed $item): bool => is_array($item) && (int) ($item['sequencial'] ?? 0) === $sequencial);

        abort_unless(is_array($correction), 404);

        $contentBase64 = $type === 'xml'
            ? ($correction['xml_base64'] ?? null)
            : ($correction['pdf_base64'] ?? null);

        abort_unless(is_string($contentBase64) && trim($contentBase64) !== '', 404);

        $binary = base64_decode($contentBase64, true);

        abort_unless($binary !== false, 404);

        $documentNumber = $fiscalDocument->document_number ?? $fiscalDocument->id;
        $filename = 'CCE-NFE-'.$documentNumber.'-'.$sequencial.'.'.$type;

        return response()->streamDownload(function () use ($binary): void {
            echo $binary;
        }, $filename, [
            'Content-Type' => $type === 'xml' ? 'application/xml' : 'application/pdf',
        ]);
    }
}
