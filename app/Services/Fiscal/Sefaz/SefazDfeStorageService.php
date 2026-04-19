<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Models\SefazDistributionDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SefazDfeStorageService
{
    public function storeRawResponse(Company $company, string $xml): string
    {
        $path = sprintf(
            'sefaz/distribution/company-%d/batches/%s-resposta-sefaz.xml',
            $company->id,
            now()->format('YmdHis') . '-' . Str::random(8),
        );

        Storage::disk('local')->put($path, $xml);

        return $path;
    }

    public function storeSummaryXml(Company $company, string $documentKey, string $nsu, string $xml): string
    {
        $path = sprintf(
            'sefaz/distribution/company-%d/%s/summary/%s-%s.xml',
            $company->id,
            $documentKey,
            $nsu !== '' ? $nsu : 'sem-nsu',
            now()->format('YmdHis'),
        );

        Storage::disk('local')->put($path, $xml);

        return $path;
    }

    public function storeFullXml(Company $company, string $documentKey, string $nsu, string $xml): string
    {
        $path = sprintf(
            'sefaz/distribution/company-%d/%s/full/%s-%s.xml',
            $company->id,
            $documentKey,
            $nsu !== '' ? $nsu : 'sem-nsu',
            now()->format('YmdHis'),
        );

        Storage::disk('local')->put($path, $xml);

        return $path;
    }

    public function read(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    public function absolutePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }
}
