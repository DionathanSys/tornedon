<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use DOMDocument;

class SefazUploadedXmlService
{
    public function __construct(
        private readonly SefazDistributionDocumentService $documentService,
        private readonly SefazDfeStorageService $storageService,
    ) {
    }

    public function register(Company $company, string $xml, ?string $originalFilename = null): SefazDistributionDocument
    {
        if (trim($xml) === '') {
            throw new \RuntimeException('O arquivo XML enviado está vazio.');
        }

        $document = new DfeDistributionDocument(
            nsu: '',
            schema: $this->inferSchema($xml, $originalFilename),
            xml: $xml,
            accessKey: $this->extractAccessKey($xml),
        );

        $rawResponsePath = $this->storageService->storeRawResponse($company, $xml);
        $record = $this->documentService->persistFromDistribution($company, $document, $rawResponsePath);

        if (! $record) {
            throw new \RuntimeException('Não foi possível registrar o XML enviado na inbox de DF-e.');
        }

        return $record;
    }

    private function inferSchema(string $xml, ?string $originalFilename): string
    {
        $dom = new DOMDocument();

        if (@ $dom->loadXML($xml)) {
            $root = $dom->documentElement?->localName;

            return match ($root) {
                'procNFe', 'nfeProc', 'NFe' => 'procNFe_v4.00.xsd',
                'resNFe' => 'resNFe_v1.01.xsd',
                'procEventoNFe' => 'procEventoNFe_v1.00.xsd',
                default => $originalFilename ?: 'uploaded_xml.xml',
            };
        }

        return $originalFilename ?: 'uploaded_xml.xml';
    }

    private function extractAccessKey(string $xml): ?string
    {
        $dom = new DOMDocument();

        if (! @ $dom->loadXML($xml)) {
            return null;
        }

        $infNFe = $dom->getElementsByTagName('infNFe')->item(0);

        if ($infNFe && preg_match('/NFe(\d{44})/', (string) $infNFe->attributes?->getNamedItem('Id')?->nodeValue, $matches) === 1) {
            return $matches[1];
        }

        $chNFe = $dom->getElementsByTagName('chNFe')->item(0)?->textContent;

        return is_string($chNFe) && preg_match('/^\d{44}$/', trim($chNFe)) === 1 ? trim($chNFe) : null;
    }
}
