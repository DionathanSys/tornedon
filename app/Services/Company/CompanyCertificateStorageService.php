<?php

namespace App\Services\Company;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanyCertificateStorageService
{
    public function save(Company $company, ?string $certificatePath): void
    {
        $currentCertificatePath = $company->certificate;

        if ($currentCertificatePath === $certificatePath) {
            Log::info('CompanyCertificateStorageService: certificado inalterado', [
                'company_id' => $company->id,
                'certificate' => $this->describePath($certificatePath),
            ]);

            return;
        }

        $disk = Storage::disk('local');

        if (filled($currentCertificatePath) && $currentCertificatePath !== $certificatePath && $disk->exists($currentCertificatePath)) {
            Log::info('CompanyCertificateStorageService: removendo certificado anterior', [
                'company_id' => $company->id,
                'certificate' => $this->describePath($currentCertificatePath),
            ]);

            $disk->delete($currentCertificatePath);
        } elseif (filled($currentCertificatePath) && $currentCertificatePath !== $certificatePath) {
            Log::warning('CompanyCertificateStorageService: certificado anterior nao encontrado para remocao', [
                'company_id' => $company->id,
                'certificate' => $this->describePath($currentCertificatePath),
            ]);
        }

        $company->update([
            'certificate' => $certificatePath,
        ]);

        Log::info('CompanyCertificateStorageService: certificado atualizado', [
            'company_id' => $company->id,
            'old_certificate' => $this->describePath($currentCertificatePath),
            'new_certificate' => $this->describePath($certificatePath),
        ]);
    }

    /**
     * @return array{name:?string,extension:?string,path_hash:?string}
     */
    private function describePath(?string $path): array
    {
        if (! filled($path)) {
            return [
                'name' => null,
                'extension' => null,
                'path_hash' => null,
            ];
        }

        return [
            'name' => basename($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'path_hash' => substr(sha1($path), 0, 12),
        ];
    }
}
