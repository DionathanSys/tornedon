<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use DOMDocument;
use DOMElement;
use DOMXPath;

class SefazDistributionFiscalDocumentXmlParser
{
    /**
     * @return array{
     *     header: array<string, mixed>,
     *     issuer: array<string, mixed>,
     *     recipient: array<string, mixed>,
     *     totals: array<string, mixed>,
     *     transport: array<string, mixed>,
     *     payment: array<string, mixed>,
     *     additional_info: array<string, mixed>,
     *     protocol: array<string, mixed>,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $xml): array
    {
        $dom = new DOMDocument();

        if (! @ $dom->loadXML($xml)) {
            throw new \RuntimeException('O XML completo armazenado para o DF-e é inválido.');
        }

        $xpath = new DOMXPath($dom);
        $infNFe = $this->firstElement($xpath, '//*[local-name()="infNFe"]');

        if (! $infNFe instanceof DOMElement) {
            throw new \RuntimeException('O XML informado não contém a estrutura infNFe esperada.');
        }

        $ide = $this->firstElement($xpath, './*[local-name()="ide"]', $infNFe);
        $emit = $this->firstElement($xpath, './*[local-name()="emit"]', $infNFe);
        $dest = $this->firstElement($xpath, './*[local-name()="dest"]', $infNFe);
        $total = $this->firstElement($xpath, './*[local-name()="total"]', $infNFe);
        $transp = $this->firstElement($xpath, './*[local-name()="transp"]', $infNFe);
        $pag = $this->firstElement($xpath, './*[local-name()="pag"]', $infNFe);
        $infAdic = $this->firstElement($xpath, './*[local-name()="infAdic"]', $infNFe);
        $protNFe = $this->firstElement($xpath, '//*[local-name()="protNFe"]');

        return [
            'header' => [
                'document_key' => $this->extractDocumentKey($infNFe, $xpath),
                'document_number' => $this->firstText($xpath, './*[local-name()="nNF"]', $ide),
                'document_series' => $this->firstText($xpath, './*[local-name()="serie"]', $ide),
                'issued_at' => $this->normalizeDateTime(
                    $this->firstText($xpath, './*[local-name()="dhEmi"]', $ide)
                        ?? $this->firstText($xpath, './*[local-name()="dEmi"]', $ide),
                ),
                'movement_at' => $this->normalizeDateTime(
                    $this->firstText($xpath, './*[local-name()="dhSaiEnt"]', $ide)
                        ?? $this->firstText($xpath, './*[local-name()="dSaiEnt"]', $ide)
                        ?? $this->firstText($xpath, './*[local-name()="dhEmi"]', $ide)
                        ?? $this->firstText($xpath, './*[local-name()="dEmi"]', $ide),
                ),
                'raw_operation_nature' => $this->firstText($xpath, './*[local-name()="natOp"]', $ide),
                'operation_nature' => $this->normalizeOperationNature($this->firstText($xpath, './*[local-name()="natOp"]', $ide)),
                'issue_purpose' => $this->normalizeIssuePurpose($this->firstText($xpath, './*[local-name()="finNFe"]', $ide)),
                'buyer_presence_indicator' => $this->normalizeBuyerPresence($this->firstText($xpath, './*[local-name()="indPres"]', $ide)),
                'is_final_consumer' => $this->normalizeBooleanFlag($this->firstText($xpath, './*[local-name()="indFinal"]', $ide), default: false),
            ],
            'issuer' => [
                'document' => $this->normalizeDocument(
                    $this->firstText($xpath, './*[local-name()="CNPJ"]', $emit)
                        ?? $this->firstText($xpath, './*[local-name()="CPF"]', $emit),
                ),
                'name' => $this->firstText($xpath, './*[local-name()="xNome"]', $emit),
                'state_tax_id' => $this->firstText($xpath, './*[local-name()="IE"]', $emit),
                'payload' => $emit instanceof DOMElement ? $this->elementToArray($emit) : [],
            ],
            'recipient' => [
                'document' => $this->normalizeDocument(
                    $this->firstText($xpath, './*[local-name()="CNPJ"]', $dest)
                        ?? $this->firstText($xpath, './*[local-name()="CPF"]', $dest),
                ),
                'name' => $this->firstText($xpath, './*[local-name()="xNome"]', $dest),
                'payload' => $dest instanceof DOMElement ? $this->elementToArray($dest) : [],
            ],
            'totals' => $total instanceof DOMElement ? $this->elementToArray($total) : [],
            'transport' => [
                'modalidade_frete' => $this->normalizeFreightModality($this->firstText($xpath, './*[local-name()="modFrete"]', $transp)),
                'payload' => $transp instanceof DOMElement ? $this->elementToArray($transp) : [],
            ],
            'payment' => $pag instanceof DOMElement ? $this->elementToArray($pag) : [],
            'additional_info' => [
                'tax_observations' => $this->firstText($xpath, './*[local-name()="infAdFisco"]', $infAdic),
                'taxpayer_observations' => $this->firstText($xpath, './*[local-name()="infCpl"]', $infAdic),
                'payload' => $infAdic instanceof DOMElement ? $this->elementToArray($infAdic) : [],
            ],
            'protocol' => $protNFe instanceof DOMElement ? $this->elementToArray($protNFe) : [],
            'items' => $this->parseItems($xpath, $infNFe),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseItems(DOMXPath $xpath, DOMElement $infNFe): array
    {
        $items = [];

        /** @var DOMElement $det */
        foreach ($xpath->query('./*[local-name()="det"]', $infNFe) as $det) {
            $product = $this->firstElement($xpath, './*[local-name()="prod"]', $det);
            $taxes = $this->firstElement($xpath, './*[local-name()="imposto"]', $det);
            $additionalInfo = $this->firstText($xpath, './*[local-name()="infAdProd"]', $det);

            if (! $product instanceof DOMElement) {
                continue;
            }

            $items[] = [
                'line' => $det->getAttribute('nItem') !== '' ? (int) $det->getAttribute('nItem') : null,
                'product_code' => $this->firstText($xpath, './*[local-name()="cProd"]', $product),
                'barcode' => $this->firstText($xpath, './*[local-name()="cEAN"]', $product),
                'description' => $this->firstText($xpath, './*[local-name()="xProd"]', $product),
                'ncm_code' => $this->firstText($xpath, './*[local-name()="NCM"]', $product),
                'cest_code' => $this->firstText($xpath, './*[local-name()="CEST"]', $product),
                'cfop_code' => $this->firstText($xpath, './*[local-name()="CFOP"]', $product),
                'product_origin' => $this->firstText($xpath, './*[local-name()="orig"]', $taxes),
                'quantity' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="qCom"]', $product), 4),
                'unit_of_measure' => $this->firstText($xpath, './*[local-name()="uCom"]', $product),
                'taxable_unit' => $this->firstText($xpath, './*[local-name()="uTrib"]', $product),
                'taxable_quantity' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="qTrib"]', $product), 4),
                'taxable_unit_price' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vUnTrib"]', $product)),
                'unit_price' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vUnCom"]', $product)),
                'total_price' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vProd"]', $product)),
                'discount_amount' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vDesc"]', $product)),
                'freight_amount' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vFrete"]', $product)),
                'insurance_amount' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vSeg"]', $product)),
                'other_expenses_amount' => $this->normalizeDecimal($this->firstText($xpath, './*[local-name()="vOutro"]', $product)),
                'additional_information' => $additionalInfo,
                'tax_data' => $taxes instanceof DOMElement ? $this->elementToArray($taxes) : [],
                'product_payload' => $this->elementToArray($product),
                'det_payload' => $this->elementToArray($det),
            ];
        }

        return $items;
    }

    private function extractDocumentKey(DOMElement $infNFe, DOMXPath $xpath): ?string
    {
        $id = $infNFe->getAttribute('Id');

        if (preg_match('/NFe(\d{44})/', $id, $matches) === 1) {
            return $matches[1];
        }

        $chNFe = $this->firstText($xpath, '//*[local-name()="chNFe"]');

        return is_string($chNFe) && preg_match('/^\d{44}$/', $chNFe) === 1 ? $chNFe : null;
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

    /**
     * @return array<string, mixed>|list<mixed>|string|null
     */
    private function elementToArray(DOMElement $element): array|string|null
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $value = $this->elementToArray($child);

            if (array_key_exists($child->localName, $children)) {
                if (! is_array($children[$child->localName]) || ! array_is_list($children[$child->localName])) {
                    $children[$child->localName] = [$children[$child->localName]];
                }

                $children[$child->localName][] = $value;
                continue;
            }

            $children[$child->localName] = $value;
        }

        if ($children === []) {
            $text = trim($element->textContent);

            return $text !== '' ? $text : null;
        }

        return $children;
    }

    private function normalizeDocument(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return in_array(strlen($digits), [11, 14], true) ? $digits : null;
    }

    private function normalizeOperationNature(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        foreach (OperationNature::cases() as $case) {
            if (mb_strtoupper($case->value) === mb_strtoupper(trim($value))) {
                return $case->value;
            }
        }

        return null;
    }

    private function normalizeIssuePurpose(?string $value): string
    {
        return match ((string) $value) {
            IssuePurpose::COMPLEMENTAR->value => IssuePurpose::COMPLEMENTAR->value,
            IssuePurpose::AJUSTE->value => IssuePurpose::AJUSTE->value,
            IssuePurpose::DEVOLUCAO->value => IssuePurpose::DEVOLUCAO->value,
            default => IssuePurpose::NORMAL->value,
        };
    }

    private function normalizeBuyerPresence(?string $value): string
    {
        foreach (BuyerPresenceIndicator::cases() as $case) {
            if ($case->value === (string) $value) {
                return $case->value;
            }
        }

        return BuyerPresenceIndicator::NAO_SE_APLICA->value;
    }

    private function normalizeFreightModality(?string $value): string
    {
        foreach (FreightModality::cases() as $case) {
            if ($case->value === (string) $value) {
                return $case->value;
            }
        }

        return FreightModality::SEM_FRETE->value;
    }

    private function normalizeBooleanFlag(?string $value, bool $default = false): bool
    {
        return match ((string) $value) {
            '1' => true,
            '0' => false,
            default => $default,
        };
    }

    private function normalizeDateTime(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDecimal(?string $value, int $scale = 2): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return number_format((float) str_replace(',', '.', $value), $scale, '.', '');
    }
}
