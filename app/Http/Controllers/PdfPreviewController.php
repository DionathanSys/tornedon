<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PdfPreviewController extends Controller
{
    public function show(Request $request, string $token)
    {
        $payload = Cache::get($this->cacheKey($token));

        abort_unless(is_array($payload), 404);

        $pdfBase64 = $payload['pdf'] ?? null;
        $filename = $payload['filename'] ?? 'preview.pdf';

        abort_unless(is_string($pdfBase64) && $pdfBase64 !== '', 404);

        $binary = base64_decode($pdfBase64, true);

        abort_unless($binary !== false, 404);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function cacheKey(string $token): string
    {
        return 'pdf_preview:' . $token;
    }
}
