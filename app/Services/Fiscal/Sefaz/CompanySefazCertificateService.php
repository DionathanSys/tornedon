<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Models\CompanyPreference;
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
     *     certificate_document:string
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
        $certificateDocument = preg_replace('/\D+/', '', (string) ($parsedCertificate['subject']['serialNumber'] ?? ''));

        if ($certificateDocument === '' || strlen($certificateDocument) < 8) {
            throw new RuntimeException('Não foi possível identificar o CNPJ/CPF no certificado A1 da empresa.');
        }

        if (substr($certificateDocument, 0, 8) !== substr($companyDocument, 0, 8)) {
            throw new RuntimeException('O certificado A1 não pertence ao mesmo CNPJ-base da empresa selecionada.');
        }

        return [
            'certificate_pem' => $certPem,
            'private_key_pem' => $keyPem,
            'certificate_path' => $certificateReference,
            'password' => $password,
            'certificate_document' => $certificateDocument,
        ];
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

        foreach ($this->candidateDisks() as $disk) {
            if (! Storage::disk($disk)->exists($reference)) {
                continue;
            }

            return Storage::disk($disk)->get($reference);
        }

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
}
