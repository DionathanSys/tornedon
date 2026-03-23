<?php

namespace App\Http\Controllers;

use App\Models\OrderAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderAttachmentController extends Controller
{
    public function download(OrderAttachment $orderAttachment): StreamedResponse
    {
        $user = Auth::user();
        $attachable = $orderAttachment->attachable;

        if (! $user || ! $attachable) {
            abort(404);
        }

        $companyId = data_get($attachable, 'company_id');

        if (! $companyId || ! $user->belongsToCompany((int) $companyId)) {
            abort(403);
        }

        if (! Storage::disk($orderAttachment->disk)->exists($orderAttachment->path)) {
            abort(404);
        }

        return Storage::disk($orderAttachment->disk)->download(
            $orderAttachment->path,
            $orderAttachment->original_name,
            ['Content-Type' => $orderAttachment->mime_type ?? 'application/octet-stream'],
        );
    }
}
