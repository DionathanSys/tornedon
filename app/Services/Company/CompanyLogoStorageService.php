<?php

namespace App\Services\Company;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

class CompanyLogoStorageService
{
    public function save(Company $company, ?string $logoPath): void
    {
        $currentLogoPath = $company->logo_path;

        if ($currentLogoPath === $logoPath) {
            return;
        }

        $disk = Storage::disk('public');

        if (filled($currentLogoPath) && $currentLogoPath !== $logoPath && $disk->exists($currentLogoPath)) {
            $disk->delete($currentLogoPath);
        }

        $company->update([
            'logo_path' => $logoPath,
        ]);
    }
}
