<?php

namespace App\Services\Fiscal\Sefaz;

use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use DOMDocument;
use DOMElement;
use DOMXPath;

class SefazDistributionDocumentParser
{
    /**
     * @return array{
     *     is_full_xml:bool,
     *     document_key:?string,
     *     issuer_document:?string,
     *     issuer_name:?string,
     *     document_number:?string,
     *     document_series:?string,
     *     issued_at:?string,
     *     total_amount:?string,
     *     items:?array<int,array<string,mixed>>,
     *     payload:array<string,mixed>
     * }
     */
    public function parse(DfeDistributionDocument $document): array
    {
        $dom = new DOMDocument();

        if (! @ $dom->loadXML($document->xml)) {
            return [
                'is_full_xml' => false,
                'document_key' => $document->accessKey,
                'issuer_document' => null,
                'issuer_name' => null,
                'document_number' => null,
                'document_series' => null,
                'issued_at' => null,
                'total_amount' => null,
                'items' => null,
                'payload' => [
                    'schema' => $document->schema,
                    'parse_error' => 'invalid_xml',
                ],
            ];
        }

        $xpath = new DOMXPath($dom);
        $root = $dom->documentElement;
        $rootName = $root?->localName ?? '';

        $isFullXml = in_array($rootName, ['procNFe', 'nfeProc', 'NFe'], true)
            || $xpath->query('//*[local-name()="infNFe"]')->length > 0;

        $issuerDocument = $this->firstText($xpath, '//*[local-name()="emit"]/*[local-name()="CNPJ"]')
            ?? $this->firstText($xpath, '//*[local-name()="CNPJ"]')
            ?? $this->firstText($xpath, '//*[local-name()="emit"]/*[local-name()="CPF"]');

        $issuedAt = $this->firstText($xpath, '//*[local-name()="dhEmi"]')
            ?? $this->firstText($xpath, '//*[local-name()="dEmi"]');

        $documentKey = $document->accessKey ?? $this->extractAccessKeyFromDom($xpath);

        $payload = [
            'schema' => $document->schema,
            'root' => $rootName,
            'tp_nf' => $this->firstText($xpath, '//*[local-name()="tpNF"]'),
            'situacao' => $this->firstText($xpath, '//*[local-name()="cSitNFe"]'),
            'protocolo' => $this->firstText($xpath, '//*[local-name()="nProt"]'),
            'received_at' => $this->firstText($xpath, '//*[local-name()="dhRecbto"]'),
        ];

        return [
            'is_full_xml' => $isFullXml,
            'document_key' => $documentKey,
            'issuer_document' => $this->normalizeDocument($issuerDocument),
            'issuer_name' => $this->firstText($xpath, '//*[local-name()="emit"]/*[local-name()="xNome"]')
                ?? $this->firstText($xpath, '//*[local-name()="xNome"]'),
            'document_number' => $this->firstText($xpath, '//*[local-name()="nNF"]'),
            'document_series' => $this->firstText($xpath, '//*[local-name()="serie"]'),
            'issued_at' => $issuedAt,
            'total_amount' => $this->normalizeDecimal($this->firstText($xpath, '//*[local-name()="vNF"]')),
            'items' => $isFullXml ? $this->parseItems($xpath) : null,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseItems(DOMXPath $xpath): array
    {
        $items = [];

        /** @var DOMElement $det */
        foreach ($xpath->query('//*[local-name()="det"]') as $det) {
            $product = $this->firstElement($xpath, './*[local-name()="prod"]', $det);
            $taxes = $this->firstElement($xpath, './*[local-name()="imposto"]', $det);
            if (! $product instanceof DOMElement) {
                continue;
            }

            $items[] = [
                'line' => $det->getAttribute('nItem') !== '' ? (int) $det->getAttribute('nItem') : null,
                'product_code' => $this->firstText($xpath, './*[local-name()="cProd"]', $product),
                'ean' => $this->firstText($xpath, './*[local-name()="cEAN"]', $product),
                'description' => $this->firstText($xpath, './*[local-name()="xProd"]', $product),
                'ncm' => $this->firstText($xpath, './*[local-name()="NCM"]', $product),
                'cest' => $this->firstText($xpath, './*[local-name()="CEST"]', $product),
                'cfop' => $this->firstText($xpath, './*[local-name()="CFOP"]', $product),
                'product_origin' => $this->firstText($xpath, './*[local-name()="orig"]', $taxes),
                'unit' => $this->firstText($xpath, './*[local-name()="uCom"]', $product),
                'quantity' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="qCom"]', $product)),
                'unit_value' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vUnCom"]', $product)),
                'total_value' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vProd"]', $product)),
            ];
        }

        return $items;
    }

    private function extractAccessKeyFromDom(DOMXPath $xpath): ?string
    {
        $chave = $this->firstText($xpath, '//*[local-name()="chNFe"]');
        if (is_string($chave) && preg_match('/\d{44}/', $chave) === 1) {
            return $chave;
        }

        $infNFe = $this->firstElement($xpath, '//*[local-name()="infNFe"]');
        if ($infNFe instanceof DOMElement) {
            $id = $infNFe->getAttribute('Id');
            if (preg_match('/NFe(\d{44})/', $id, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function firstText(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?string
    {
        $node = $xpath->query($expression, $context)->item(0);
        $value = trim((string) $node?->textContent);

        return $value !== '' ? $value : null;
    }

    private function firstElement(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?DOMElement
    {
        $node = $xpath->query($expression, $context)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function normalizeDocument(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return in_array(strlen($digits), [11, 14], true) ? $digits : null;
    }

    private function normalizeDecimal(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return number_format((float) str_replace(',', '.', $value), 2, '.', '');
    }
}
