<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentXmlExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FiscalDocumentXmlExportDownloadController extends Controller
{
    public function __invoke(Request $request, FiscalDocumentXmlExport $export, string $token)
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $user = $request->user();
        if (! $user || (int) $export->user_id !== (int) $user->id) {
            abort(403);
        }

        if (! $user->belongsToCompany((int) $export->company_id)) {
            abort(403);
        }

        if (! hash_equals((string) $export->download_token, $token)) {
            abort(403);
        }

        if (! $export->isDownloadAvailable()) {
            abort(410);
        }

        $disk = $export->zip_disk ?: 'local';
        $path = (string) $export->zip_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $filename = sprintf(
            'notas-saida-xml-%s-a-%s.zip',
            $export->starts_at?->format('Y-m-d') ?? 'inicio',
            $export->ends_at?->format('Y-m-d') ?? 'fim',
        );

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
