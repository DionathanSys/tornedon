<?php

namespace App\Services\Invoice\Actions;

use App\Enums\AttachmentType;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\DTO\EmailAttachment;
use App\Services\Email\DTO\EmailMessage;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Requisition\Actions\PrintRequisitionPdfAction;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendInvoiceEmailAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly EmailProviderInterface $emailProvider,
    ) {}

    public function execute(Invoice $invoice, string $subject, string $body, int $userId): bool
    {
        try {
            $invoice->loadMissing([
                'customer',
                'company',
                'fiscalDocuments',
                'serviceOrders',
                'requisitions',
            ]);

            if ($invoice->fiscalDocuments->isEmpty()) {
                $this->setError('A fatura não possui documento fiscal vinculado para envio.');
                return false;
            }

            $companyPartner = $this->resolveCompanyPartner($invoice);
            if (! $companyPartner instanceof CompanyPartner) {
                $this->setError('Nenhum vínculo ativo entre empresa e cliente foi encontrado para o envio.');
                return false;
            }

            [$to, $cc, $bcc] = $this->resolveRecipients($companyPartner);
            if ($to === []) {
                $this->setError('Nenhum destinatário válido foi encontrado para o cliente desta fatura.');
                return false;
            }

            $attachments = $this->buildAttachments($invoice);

            $htmlBody = nl2br(e(trim($body)));

            $message = new EmailMessage(
                to: $to,
                cc: $cc,
                subject: trim($subject),
                html: $htmlBody,
                text: trim($body),
                fromEmail: (string) config('mail.from.address'),
                fromName: (string) config('mail.from.name'),
                attachments: $attachments,
            );

            $providerResult = $this->emailProvider->send($message);

            Log::info('SendInvoiceEmailAction: e-mail da fatura enviado com sucesso', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'company_partner_id' => $companyPartner->id,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'attachments_count' => count($attachments),
                'provider_message_id' => $providerResult['provider_message_id'] ?? null,
                'user_id' => $userId,
            ]);

            $this->setSuccess();

            return true;
        } catch (\Throwable $e) {
            Log::error('SendInvoiceEmailAction: falha ao enviar e-mail da fatura', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'message' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            $this->setError('Erro ao enviar e-mail da fatura: ' . $e->getMessage());

            return false;
        }
    }

    private function resolveCompanyPartner(Invoice $invoice): ?CompanyPartner
    {
        return CompanyPartner::query()
            ->where('company_id', $invoice->company_id)
            ->where('partner_id', $invoice->customer_id)
            ->where('is_active', true)
            ->with([
                'contacts' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('email'),
            ])
            ->first();
    }

    /**
     * @return array{string[],string[],string[]}
     */
    private function resolveRecipients(CompanyPartner $companyPartner): array
    {
        $to = $this->parseEmails((string) ($companyPartner->email_to_override ?? ''));
        if ($to === []) {
            $to = $companyPartner->contacts
                ->pluck('email')
                ->map(fn ($email): string => trim((string) $email))
                ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()
                ->values()
                ->all();
        }

        $cc = $this->mergeEmails(
            $this->parseEmails((string) ($companyPartner->email_cc_override ?? '')),
            $this->parseEmails((string) CompanyPreference::get('email_cc', (int) $companyPartner->company_id, '')),
        );

        $bcc = $this->mergeEmails(
            $this->parseEmails((string) ($companyPartner->email_bcc_override ?? '')),
        );

        return [$to, $cc, $bcc];
    }

    /**
     * @return EmailAttachment[]
     */
    private function buildAttachments(Invoice $invoice): array
    {
        $attachments = [];
        $attachments[] = $this->buildInvoicePdfAttachment($invoice);

        foreach ($invoice->fiscalDocuments as $fiscalDocument) {
            $attachments[] = $this->buildFiscalPdfAttachment($fiscalDocument);
            $attachments[] = $this->buildFiscalXmlAttachment($fiscalDocument);
        }

        foreach ($invoice->serviceOrders as $serviceOrder) {
            $attachments[] = $this->buildServiceOrderPdfAttachment($serviceOrder);
        }

        foreach ($invoice->requisitions as $requisition) {
            $attachments[] = $this->buildRequisitionPdfAttachment($requisition);
        }

        return $attachments;
    }

    private function buildInvoicePdfAttachment(Invoice $invoice): EmailAttachment
    {
        $pdfAction = app(PrintInvoicePdfAction::class);
        $pdf = $pdfAction->execute($invoice);

        if (! is_string($pdf) || trim($pdf) === '' || $pdfAction->hasError()) {
            throw new \RuntimeException('Não foi possível gerar o PDF da fatura.');
        }

        $number = (string) ($invoice->invoice_number ?: $invoice->id);

        return new EmailAttachment(
            filename: "fatura-{$number}.pdf",
            contentBase64: $pdf,
            mimeType: 'application/pdf',
            kind: 'invoice_pdf',
        );
    }

    private function buildFiscalPdfAttachment(FiscalDocument $fiscalDocument): EmailAttachment
    {
        $number = (string) ($fiscalDocument->document_number ?: $fiscalDocument->id);
        $persisted = $this->buildPersistedFiscalAttachment($fiscalDocument, 'danfe', "danfe-{$number}.pdf");

        if ($persisted instanceof EmailAttachment) {
            return $persisted;
        }

        if ($fiscalDocument->isNfse()) {
            $service = app(NfseDocumentService::class);
            $pdf = $service->pdf($fiscalDocument, 0);

            if (! is_string($pdf) || trim($pdf) === '') {
                throw new \RuntimeException("Não foi possível gerar o PDF fiscal do documento #{$number}.");
            }

            return new EmailAttachment(
                filename: "nfse-{$number}.pdf",
                contentBase64: $pdf,
                mimeType: 'application/pdf',
                kind: 'fiscal_pdf',
            );
        }

        $service = app(NfeDocumentService::class);
        $pdf = $service->danfe($fiscalDocument, 0);

        if (! is_string($pdf) || trim($pdf) === '') {
            throw new \RuntimeException("Não foi possível gerar a DANFE do documento #{$number}.");
        }

        return new EmailAttachment(
            filename: "danfe-{$number}.pdf",
            contentBase64: $pdf,
            mimeType: 'application/pdf',
            kind: 'fiscal_pdf',
        );
    }

    private function buildFiscalXmlAttachment(FiscalDocument $fiscalDocument): EmailAttachment
    {
        $number = (string) ($fiscalDocument->document_number ?: $fiscalDocument->id);
        $persisted = $this->buildPersistedFiscalAttachment($fiscalDocument, 'xml', "nf-{$number}.xml");

        if ($persisted instanceof EmailAttachment) {
            return $persisted;
        }

        $xmlBase64 = $this->extractFiscalXmlBase64($fiscalDocument);

        if ($xmlBase64 === null) {
            throw new \RuntimeException("Não foi possível localizar o XML do documento fiscal #{$number}.");
        }

        return new EmailAttachment(
            filename: "nf-{$number}.xml",
            contentBase64: $xmlBase64,
            mimeType: 'application/xml',
            kind: 'fiscal_xml',
        );
    }

    private function buildServiceOrderPdfAttachment(ServiceOrder $serviceOrder): EmailAttachment
    {
        $pdfAction = app(PrintServiceOrderPdfAction::class);
        $pdf = $pdfAction->execute($serviceOrder);

        if (! is_string($pdf) || trim($pdf) === '' || $pdfAction->hasError()) {
            $number = (string) ($serviceOrder->number ?: $serviceOrder->id);
            throw new \RuntimeException("Não foi possível gerar o PDF da ordem de serviço #{$number}.");
        }

        $number = (string) ($serviceOrder->number ?: $serviceOrder->id);

        return new EmailAttachment(
            filename: "ordem-servico-{$number}.pdf",
            contentBase64: $pdf,
            mimeType: 'application/pdf',
            kind: 'service_order_pdf',
        );
    }

    private function buildRequisitionPdfAttachment(Requisition $requisition): EmailAttachment
    {
        $pdfAction = app(PrintRequisitionPdfAction::class);
        $pdf = $pdfAction->execute($requisition);

        if (! is_string($pdf) || trim($pdf) === '' || $pdfAction->hasError()) {
            $number = (string) ($requisition->number ?: $requisition->id);
            throw new \RuntimeException("Não foi possível gerar o PDF da requisição #{$number}.");
        }

        $number = (string) ($requisition->number ?: $requisition->id);

        return new EmailAttachment(
            filename: "requisicao-{$number}.pdf",
            contentBase64: $pdf,
            mimeType: 'application/pdf',
            kind: 'requisition_pdf',
        );
    }

    private function buildPersistedFiscalAttachment(
        FiscalDocument $fiscalDocument,
        string $kind,
        string $fallbackFilename,
    ): ?EmailAttachment {
        if (! method_exists($fiscalDocument, 'attachmentsOfType')) {
            return null;
        }

        $record = $fiscalDocument->attachmentsOfType(AttachmentType::FISCAL_DOCUMENT)
            ->where('is_current', true)
            ->orderByDesc('version')
            ->get()
            ->first(function ($attachment) use ($kind): bool {
                return Str::lower((string) Arr::get($attachment->metadata, 'kind')) === $kind;
            });

        if (! $record) {
            return null;
        }

        if (! Storage::disk((string) $record->disk)->exists((string) $record->path)) {
            return null;
        }

        $binary = Storage::disk((string) $record->disk)->get((string) $record->path);

        return new EmailAttachment(
            filename: (string) ($record->original_name ?: $fallbackFilename),
            contentBase64: base64_encode($binary),
            mimeType: (string) ($record->mime_type ?: 'application/octet-stream'),
            kind: $kind,
        );
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

            if (base64_decode($value, true) !== false) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function parseEmails(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/[;,\r\n]+/', $raw) ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param string[] ...$lists
     * @return string[]
     */
    private function mergeEmails(array ...$lists): array
    {
        return collect($lists)
            ->flatten()
            ->map(fn ($email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
