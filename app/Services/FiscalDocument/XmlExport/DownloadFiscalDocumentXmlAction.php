<?php

namespace App\Services\FiscalDocument\XmlExport;

use App\Models\FiscalDocument;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\NfseConfigService;
use App\Traits\HandlesActionResponse;
use CloudDfe\SdkPHP\Nfe;
use CloudDfe\SdkPHP\Nfse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DownloadFiscalDocumentXmlAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?string
    {
        try {
            if (! $fiscalDocument->isNfeAuthorized() && ! $fiscalDocument->isNfseAuthorized()) {
                $this->setError('Somente documento fiscal autorizado pode ter XML exportado.');

                return null;
            }

            if (blank($fiscalDocument->document_key)) {
                $this->setError('Chave de acesso não encontrada no documento fiscal.');

                return null;
            }

            $response = $fiscalDocument->isNfse()
                ? $this->downloadNfse($fiscalDocument)
                : $this->downloadNfe($fiscalDocument);

            $xml = $this->extractXml($response);

            if ($xml === null) {
                $message = is_object($response) && isset($response->mensagem)
                    ? (string) $response->mensagem
                    : 'A API não retornou XML para este documento fiscal.';

                $this->setError($message, is_object($response) ? (array) ($response->erros ?? []) : []);

                return null;
            }

            $this->setSuccess();

            return $xml;
        } catch (\Throwable $e) {
            $this->setError('Erro ao baixar XML do documento fiscal: '.$e->getMessage());

            return null;
        }
    }

    private function downloadNfe(FiscalDocument $fiscalDocument): object
    {
        $configService = app(NfeConfigService::class);
        $sdk = new Nfe($configService->buildSdkParams($fiscalDocument->company_id));

        return $sdk->download(['chave' => $fiscalDocument->document_key]);
    }

    private function downloadNfse(FiscalDocument $fiscalDocument): object
    {
        $configService = app(NfseConfigService::class);
        $sdk = new Nfse($configService->buildSdkParams($fiscalDocument->company_id));

        return $sdk->consulta(['chave' => $fiscalDocument->document_key]);
    }

    private function extractXml(mixed $response): ?string
    {
        $data = json_decode(json_encode($response), true);
        if (! is_array($data)) {
            return null;
        }

        $candidates = [
            Arr::get($data, 'xml'),
            Arr::get($data, 'xml_base64'),
            Arr::get($data, 'conteudo'),
            Arr::get($data, 'arquivo'),
            Arr::get($data, 'data.xml'),
            Arr::get($data, 'data.xml_base64'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $value = trim($candidate);
            if (Str::startsWith($value, '<')) {
                return $value;
            }

            $decoded = base64_decode($value, true);
            if ($decoded !== false && Str::startsWith(ltrim($decoded), '<')) {
                return $decoded;
            }
        }

        return null;
    }
}
