<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyLogoController extends Controller
{
    public function show(Request $request, Company $company): StreamedResponse
    {
        abort_unless($request->user()?->belongsToCompany((int) $company->id), 403);

        $path = $company->logo_path;

        if (! filled($path)) {
            abort(404);
        }

        $disk = Storage::disk(config('uploads.logo_disk'));

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
