<?php

namespace App\Http\Controllers;

use App\Models\EmailDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmailDispatchAttachmentController extends Controller
{
    public function show(Request $request, EmailDispatch $emailDispatch, string $token)
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $manifest = collect($emailDispatch->attachments_manifest ?? []);

        /** @var array<string,mixed>|null $entry */
        $entry = $manifest->first(
            fn (array $item): bool =>
                ($item['mode'] ?? null) === 'signed_link'
                && (string) ($item['token'] ?? '') === $token
                && is_string($item['path'] ?? null)
        );

        if (! is_array($entry)) {
            abort(404);
        }

        $path = (string) $entry['path'];
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $filename = (string) ($entry['filename'] ?? basename($path));
        $mime = (string) ($entry['mime'] ?? 'application/octet-stream');

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}

