<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Company;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;

final class SefazDfeDocumentClassifier
{
    public function __construct(private readonly SefazDistributionDocumentParser $parser) {}

    /**
     * @param  array<string, mixed>|null  $parsed
     */
    public function isTakenBy(
        Company $company,
        DfeDistributionDocument $document,
        ?array $parsed = null,
    ): bool {
        return $this->rejectionReason($company, $document, $parsed) === null;
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     */
    public function rejectionReason(
        Company $company,
        DfeDistributionDocument $document,
        ?array $parsed = null,
    ): ?string {
        $parsed ??= $this->parser->parse($document);
        $companyDocument = $this->normalizeDocument($company->document_number);

        if ($companyDocument === null) {
            return 'A empresa ativa não possui um CNPJ válido cadastrado.';
        }

        $issuerDocument = $this->normalizeDocument($parsed['issuer_document'] ?? null);

        if ($issuerDocument === null) {
            return 'O XML não possui um CNPJ de emitente válido para a inbox de DF-e.';
        }

        if ($issuerDocument === $companyDocument) {
            return 'O XML foi emitido pela própria empresa ativa. A inbox de DF-e aceita apenas documentos recebidos de fornecedores.';
        }

        if (($parsed['is_full_xml'] ?? false) === true) {
            return $this->normalizeDocument($parsed['recipient_document'] ?? null) === $companyDocument
                ? null
                : 'O CNPJ do destinatário do XML não corresponde ao CNPJ da empresa ativa. Selecione a empresa destinatária para enviar este XML.';
        }

        return ($parsed['is_summary_xml'] ?? false) === true
            ? null
            : 'O arquivo enviado não é um XML de NF-e ou resumo de NF-e compatível com a inbox de DF-e.';
    }

    private function normalizeDocument(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 14 ? $digits : null;
    }
}
