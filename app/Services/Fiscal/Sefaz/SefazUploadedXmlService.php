<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use DOMDocument;
use InvalidArgumentException;

class SefazUploadedXmlService
{
    public function __construct(
        private readonly SefazDistributionDocumentService $documentService,
        private readonly SefazDfeStorageService $storageService,
        private readonly SefazDistributionDocumentParser $parser,
        private readonly SefazDfeDocumentClassifier $documentClassifier,
    ) {}

    public function register(Company $company, string $xml, ?string $originalFilename = null): SefazDistributionDocument
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('O arquivo XML enviado está vazio.');
        }

        $document = new DfeDistributionDocument(
            nsu: '',
            schema: $this->inferSchema($xml, $originalFilename),
            xml: $xml,
            accessKey: $this->extractAccessKey($xml),
        );

        $parsed = $this->parser->parse($document);

        if (($parsed['payload']['parse_error'] ?? null) === 'invalid_xml') {
            throw new InvalidArgumentException('O arquivo enviado não contém um XML válido.');
        }

        if (! $parsed['is_full_xml'] && ! $parsed['is_summary_xml']) {
            throw new InvalidArgumentException('O arquivo enviado não é um XML de NF-e ou resumo de NF-e compatível com a inbox de DF-e.');
        }

        if (! is_string($parsed['document_key']) || preg_match('/^\d{44}$/', $parsed['document_key']) !== 1) {
            throw new InvalidArgumentException('O XML não contém uma chave de acesso de NF-e válida com 44 dígitos.');
        }

        $rejectionReason = $this->documentClassifier->rejectionReason($company, $document, $parsed);

        if ($rejectionReason !== null) {
            throw new InvalidArgumentException($rejectionReason);
        }

        $rawResponsePath = $this->storageService->storeRawResponse($company, $xml);
        $record = $this->documentService->persistFromDistribution($company, $document, $rawResponsePath);

        if (! $record) {
            throw new \RuntimeException('Não foi possível registrar o XML enviado na inbox de DF-e.');
        }

        return $record;
    }

    private function inferSchema(string $xml, ?string $originalFilename): string
    {
        $dom = new DOMDocument;

        if (@$dom->loadXML($xml)) {
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
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml)) {
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
