<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PwaServiceWorkerController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $path = public_path('build/sw.js');

        abort_unless(is_file($path), 503, 'Execute npm run build para gerar o service worker.');

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
