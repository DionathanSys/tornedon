<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Models\CompanyPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CompanySefazCertificateService
{
    public const PASSWORD_PREFERENCE_KEY = 'sefaz.a1_password';

    /**
     * @return array{
     *     certificate_pem:string,
     *     private_key_pem:string,
     *     certificate_path:string,
     *     password:string,
     *     certificate_document:?string
     * }
     */
    public function loadForCompany(Company $company): array
    {
        $companyDocument = preg_replace('/\D+/', '', (string) $company->document_number);
        if ($companyDocument === null || strlen($companyDocument) !== 14) {
            throw new RuntimeException('Informe o CNPJ da empresa antes de consultar DF-e na SEFAZ.');
        }

        $certificateReference = trim((string) $company->certificate);
        if ($certificateReference === '') {
            throw new RuntimeException('Nenhum certificado A1 foi configurado para esta empresa.');
        }

        $password = (string) CompanyPreference::get(self::PASSWORD_PREFERENCE_KEY, $company->id, '');
        if ($password === '') {
            throw new RuntimeException('Cadastre a senha do certificado A1 para consultar DF-e na SEFAZ.');
        }

        $binary = $this->readCertificateBinary($certificateReference);
        $pkcs12 = [];

        if (! openssl_pkcs12_read($binary, $pkcs12, $password)) {
            throw new RuntimeException('Não foi possível abrir o certificado A1 com a senha informada.');
        }

        $certPem = trim((string) ($pkcs12['cert'] ?? ''));
        $keyPem = trim((string) ($pkcs12['pkey'] ?? ''));

        if ($certPem === '' || $keyPem === '') {
            throw new RuntimeException('O certificado A1 não contém certificado público e chave privada válidos.');
        }

        $parsedCertificate = openssl_x509_parse($certPem);
        if (! is_array($parsedCertificate)) {
            throw new RuntimeException('Não foi possível interpretar o certificado A1 da empresa.');
        }

        $certificateDocument = $this->extractCertificateDocument($parsedCertificate, $companyDocument);

        if ($certificateDocument !== null && substr($certificateDocument, 0, 8) !== substr($companyDocument, 0, 8)) {
            throw new RuntimeException('O certificado A1 não pertence ao mesmo CNPJ-base da empresa selecionada.');
        }

        if ($certificateDocument === null) {
            Log::warning('CompanySefazCertificateService: certificado carregado sem CNPJ/CPF identificavel no parse', [
                'company_id' => $company->id,
                'company_document' => $companyDocument,
                'certificate_path' => $certificateReference,
                'subject' => $parsedCertificate['subject'] ?? null,
                'name' => $parsedCertificate['name'] ?? null,
            ]);
        }

        return [
            'certificate_pem' => $certPem,
            'private_key_pem' => $keyPem,
            'certificate_path' => $certificateReference,
            'password' => $password,
            'certificate_document' => $certificateDocument,
        ];
    }

    public function extractCertificateDocument(array $parsedCertificate, ?string $companyDocument = null): ?string
    {
        $candidates = [];

        $subjectSerialNumber = $this->normalizeDocumentCandidate((string) ($parsedCertificate['subject']['serialNumber'] ?? ''));
        if ($subjectSerialNumber !== null) {
            $candidates[] = $subjectSerialNumber;
        }

        foreach ($this->collectDocumentCandidates($parsedCertificate) as $candidate) {
            $candidates[] = $candidate;
        }

        $candidates = array_values(array_unique(array_filter($candidates)));

        if ($companyDocument !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate === $companyDocument) {
                    return $candidate;
                }
            }

            foreach ($candidates as $candidate) {
                if (substr($candidate, 0, 8) === substr($companyDocument, 0, 8)) {
                    return $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (strlen($candidate) === 14) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (strlen($candidate) === 11) {
                return $candidate;
            }
        }

        return null;
    }

    private function readCertificateBinary(string $reference): string
    {
        if ($this->isAbsolutePath($reference)) {
            if (! is_file($reference) || ! is_readable($reference)) {
                throw new RuntimeException('O arquivo do certificado A1 configurado não está acessível no servidor.');
            }

            $binary = file_get_contents($reference);
            if ($binary === false) {
                throw new RuntimeException('Não foi possível ler o arquivo do certificado A1.');
            }

            return $binary;
        }

        $relativeCandidates = $this->candidateRelativeReferences($reference);

        foreach ($this->candidateAbsolutePaths($reference) as $absolutePath) {
            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                continue;
            }

            $binary = file_get_contents($absolutePath);
            if ($binary === false) {
                continue;
            }

            Log::info('CompanySefazCertificateService: certificado encontrado por caminho absoluto legadoo', [
                'reference' => $reference,
                'resolved_path' => $absolutePath,
            ]);

            return $binary;
        }

        foreach ($this->candidateDisks() as $disk) {
            foreach ($relativeCandidates as $relativeReference) {
                if (! Storage::disk($disk)->exists($relativeReference)) {
                    continue;
                }

                Log::info('CompanySefazCertificateService: certificado encontrado no storage', [
                    'reference' => $reference,
                    'resolved_reference' => $relativeReference,
                    'disk' => $disk,
                ]);

                return Storage::disk($disk)->get($relativeReference);
            }
        }

        Log::error('CompanySefazCertificateService: certificado nao encontrado', [
            'reference' => $reference,
            'relative_candidates' => $relativeCandidates,
            'absolute_candidates' => $this->candidateAbsolutePaths($reference),
            'candidate_disks' => $this->candidateDisks(),
        ]);

        throw new RuntimeException('O arquivo do certificado A1 configurado não foi encontrado no storage da aplicação.');
    }

    private function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path) || str_starts_with($path, '\\\\');
    }

    /**
     * @return array<int,string>
     */
    private function candidateDisks(): array
    {
        $default = (string) config('filesystems.default', 'local');

        return array_values(array_unique([$default, 'local', 'public']));
    }

    /**
     * @return array<int,string>
     */
    private function candidateAbsolutePaths(string $reference): array
    {
        $candidates = [];

        foreach ($this->candidateRelativeReferences($reference) as $normalized) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized);

            $candidates[] = storage_path($normalized);
            $candidates[] = storage_path('app' . DIRECTORY_SEPARATOR . $normalized);
            $candidates[] = storage_path('app/private' . DIRECTORY_SEPARATOR . $normalized);
            $candidates[] = storage_path('app/public' . DIRECTORY_SEPARATOR . $normalized);
        }

        return array_values(array_unique(array_map(
            static fn (string $path): string => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
            $candidates,
        )));
    }

    /**
     * @return array<int,string>
     */
    private function candidateRelativeReferences(string $reference): array
    {
        $normalized = ltrim(str_replace(['\\', '/'], '/', trim($reference)), '/');
        $candidates = [$normalized];

        foreach (['storage/', 'storage/app/', 'app/', 'app/private/', 'app/public/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $candidates[] = ltrim(substr($normalized, strlen($prefix)), '/');
            }
        }

        if (! str_starts_with($normalized, 'private/')) {
            $candidates[] = 'private/' . $normalized;
        }

        if (! str_starts_with($normalized, 'public/')) {
            $candidates[] = 'public/' . $normalized;
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    /**
     * @return array<int,string>
     */
    private function collectDocumentCandidates(mixed $value): array
    {
        if (is_array($value)) {
            $candidates = [];

            foreach ($value as $item) {
                foreach ($this->collectDocumentCandidates($item) as $candidate) {
                    $candidates[] = $candidate;
                }
            }

            return $candidates;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        preg_match_all('/\b\d{11,14}\b/', $value, $matches);

        return array_values(array_filter(
            array_map(fn (string $candidate): ?string => $this->normalizeDocumentCandidate($candidate), $matches[0] ?? [])
        ));
    }

    private function normalizeDocumentCandidate(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (! in_array(strlen($digits), [11, 14], true)) {
            return null;
        }

        return $digits;
    }
}
