<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enums\AttachmentType;
use App\Models\FiscalDocument;
use App\Services\Attachments\AttachmentService;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Traits\HandlesActionResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StoreFiscalDocumentAttachmentsAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        try {
            $number = (string) ($fiscalDocument->document_number ?: $fiscalDocument->id);
            $attachmentService = app(AttachmentService::class);

            $danfeBase64 = $this->resolveDanfeBase64($fiscalDocument);
            if ($danfeBase64 !== null) {
                $this->storeBase64Attachment(
                    attachmentService: $attachmentService,
                    fiscalDocument: $fiscalDocument,
                    filename: ($fiscalDocument->isNfse() ? "nfse-{$number}" : "danfe-{$number}") . '.pdf',
                    mimeType: 'application/pdf',
                    contentBase64: $danfeBase64,
                    kind: 'danfe',
                );
            }

            $xmlBase64 = $this->extractFiscalXmlBase64($fiscalDocument);
            if ($xmlBase64 !== null) {
                $this->storeBase64Attachment(
                    attachmentService: $attachmentService,
                    fiscalDocument: $fiscalDocument,
                    filename: "nf-{$number}.xml",
                    mimeType: 'application/xml',
                    contentBase64: $xmlBase64,
                    kind: 'xml',
                );
            }

            $this->setSuccess();
            return true;
        } catch (\Throwable $e) {
            $this->setError('Erro ao salvar anexos do documento fiscal: ' . $e->getMessage());
            return false;
        }
    }

    private function resolveDanfeBase64(FiscalDocument $fiscalDocument): ?string
    {
        if ($fiscalDocument->isNfse()) {
            $service = app(NfseDocumentService::class);
            $pdf = $service->pdf($fiscalDocument, 0);

            return is_string($pdf) && trim($pdf) !== '' ? $pdf : null;
        }

        $service = app(NfeDocumentService::class);
        $pdf = $service->danfe($fiscalDocument, 0);

        return is_string($pdf) && trim($pdf) !== '' ? $pdf : null;
    }

    private function storeBase64Attachment(
        AttachmentService $attachmentService,
        FiscalDocument $fiscalDocument,
        string $filename,
        string $mimeType,
        string $contentBase64,
        string $kind,
    ): void {
        $binary = base64_decode($contentBase64, true);
        if ($binary === false) {
            throw new \RuntimeException("Conteúdo inválido para o anexo '{$kind}'.");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'fd-attach-');
        if ($tmpPath === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário para anexo fiscal.');
        }

        try {
            file_put_contents($tmpPath, $binary);

            $uploadedFile = new UploadedFile(
                path: $tmpPath,
                originalName: $filename,
                mimeType: $mimeType,
                error: null,
                test: true,
            );

            $attachmentService->upload(
                owner: $fiscalDocument,
                file: $uploadedFile,
                type: AttachmentType::FISCAL_DOCUMENT,
                options: [
                    'idempotency_key' => $this->idempotencyKey($fiscalDocument, $kind),
                    'metadata' => [
                        'kind' => $kind,
                        'document_key' => $fiscalDocument->document_key,
                        'document_number' => $fiscalDocument->document_number,
                        'authorized_at' => optional($fiscalDocument->authorized_at ?? $fiscalDocument->confirmed_at)->toDateTimeString(),
                    ],
                ],
            );
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function idempotencyKey(FiscalDocument $fiscalDocument, string $kind): string
    {
        return hash('sha256', implode(':', [
            'fiscal-document',
            (string) $fiscalDocument->id,
            $kind,
            (string) ($fiscalDocument->document_key ?: 'no-key'),
            (string) ($fiscalDocument->updated_at?->toIso8601String() ?: now()->toIso8601String()),
        ]));
    }

    private function extractFiscalXmlBase64(FiscalDocument $fiscalDocument): ?string
    {
        $candidates = [
            Arr::get($fiscalDocument->nfe_payload, 'xml'),
            Arr::get($fiscalDocument->nfe_payload, 'xml_base64'),
            Arr::get($fiscalDocument->nfse_payload, 'xml'),
            Arr::get($fiscalDocument->nfse_payload, 'xml_base64'),
            Arr::get($fiscalDocument->logs, 'xml'),
            Arr::get($fiscalDocument->logs, 'xml_base64'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $value = trim($candidate);

            if (Str::startsWith($value, '<')) {
                return base64_encode($value);
            }

            $decoded = base64_decode($value, true);
            if ($decoded !== false) {
                return $value;
            }
        }

        return null;
    }
}
