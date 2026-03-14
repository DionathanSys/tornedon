<?php

namespace App\Services\Email;

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
use Illuminate\Support\Facades\Log;

class CustomerDocumentEmailService
{
    public function __construct(
        private readonly EmailProviderInterface $emailProvider,
    ) {}

    public function sendServiceOrderGenerated(ServiceOrder $serviceOrder): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $serviceOrder->loadMissing(['company', 'customer']);

        $pdfAction = new PrintServiceOrderPdfAction();
        $pdfBase64 = $pdfAction->execute($serviceOrder);

        if ($pdfBase64 === null || $pdfAction->hasError()) {
            Log::warning('CustomerDocumentEmailService: falha ao gerar PDF de OS para envio de e-mail', [
                'service_order_id' => $serviceOrder->id,
                'message' => $pdfAction->getMessage(),
            ]);
            return false;
        }

        $number = $serviceOrder->number ?: (string) $serviceOrder->id;
        $subject = "Ordem de Serviço {$number}";

        $body = sprintf(
            'Olá,%sA ordem de serviço %s foi gerada e segue em anexo.',
            '<br><br>',
            e($number)
        );

        return $this->sendToCustomer(
            companyId: $serviceOrder->company_id,
            partnerId: $serviceOrder->customer_id,
            subject: $subject,
            bodyHtml: $body,
            attachments: [
                new EmailAttachment(
                    filename: "ordem-servico-{$number}.pdf",
                    contentBase64: $pdfBase64,
                    mimeType: 'application/pdf',
                ),
            ],
            context: [
                'document_type' => 'service_order',
                'document_id' => $serviceOrder->id,
            ],
        );
    }

    public function sendRequisitionGenerated(Requisition $requisition): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $requisition->loadMissing(['company', 'customer']);

        $pdfAction = new PrintRequisitionPdfAction();
        $pdfBase64 = $pdfAction->execute($requisition);

        if ($pdfBase64 === null || $pdfAction->hasError()) {
            Log::warning('CustomerDocumentEmailService: falha ao gerar PDF de requisição para envio de e-mail', [
                'requisition_id' => $requisition->id,
                'message' => $pdfAction->getMessage(),
            ]);
            return false;
        }

        $number = $requisition->number ?: (string) $requisition->id;
        $subject = "Requisição {$number}";

        $body = sprintf(
            'Olá,%sA requisição %s foi gerada e segue em anexo.',
            '<br><br>',
            e($number)
        );

        return $this->sendToCustomer(
            companyId: $requisition->company_id,
            partnerId: $requisition->customer_id,
            subject: $subject,
            bodyHtml: $body,
            attachments: [
                new EmailAttachment(
                    filename: "requisicao-{$number}.pdf",
                    contentBase64: $pdfBase64,
                    mimeType: 'application/pdf',
                ),
            ],
            context: [
                'document_type' => 'requisition',
                'document_id' => $requisition->id,
            ],
        );
    }

    public function sendFiscalDocumentAuthorized(FiscalDocument $fiscalDocument): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($this->wasFiscalDocumentEmailSent($fiscalDocument)) {
            return true;
        }

        $fiscalDocument->loadMissing(['company', 'customer']);

        $attachments = $this->buildFiscalDocumentAttachments($fiscalDocument);
        if ($attachments === []) {
            return false;
        }

        $number = $fiscalDocument->document_number ?: (string) $fiscalDocument->id;
        $subject = "Nota Fiscal {$number}";

        $body = sprintf(
            'Olá,%sA nota fiscal %s foi autorizada e segue em anexo.',
            '<br><br>',
            e($number)
        );

        $sent = $this->sendToCustomer(
            companyId: $fiscalDocument->company_id,
            partnerId: $fiscalDocument->customer_id,
            subject: $subject,
            bodyHtml: $body,
            attachments: $attachments,
            context: [
                'document_type' => 'fiscal_document',
                'document_id' => $fiscalDocument->id,
                'document_key' => $fiscalDocument->document_key,
            ],
        );

        if ($sent) {
            $this->markFiscalDocumentEmailAsSent($fiscalDocument);
        }

        return $sent;
    }


    public function sendServiceOrderStatusUpdated(ServiceOrder $serviceOrder, string $oldStatus, string $newStatus): bool
    {
        return $this->sendStatusUpdateNotification(
            documentType: 'service_order',
            companyId: (int) $serviceOrder->company_id,
            partnerId: (int) $serviceOrder->customer_id,
            documentNumber: (string) ($serviceOrder->number ?: $serviceOrder->id),
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            partnerName: (string) ($serviceOrder->customer?->name ?? ''),
            context: [
                'document_id' => $serviceOrder->id,
            ],
        );
    }

    public function sendRequisitionStatusUpdated(Requisition $requisition, string $oldStatus, string $newStatus): bool
    {
        return $this->sendStatusUpdateNotification(
            documentType: 'requisition',
            companyId: (int) $requisition->company_id,
            partnerId: (int) $requisition->customer_id,
            documentNumber: (string) ($requisition->number ?: $requisition->id),
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            partnerName: (string) ($requisition->customer?->name ?? ''),
            context: [
                'document_id' => $requisition->id,
            ],
        );
    }

    public function sendInvoiceStatusUpdated(Invoice $invoice, string $oldStatus, string $newStatus): bool
    {
        return $this->sendStatusUpdateNotification(
            documentType: 'invoice',
            companyId: (int) $invoice->company_id,
            partnerId: (int) $invoice->customer_id,
            documentNumber: (string) ($invoice->invoice_number ?: $invoice->id),
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            partnerName: (string) ($invoice->customer?->name ?? ''),
            context: [
                'document_id' => $invoice->id,
            ],
        );
    }

    public function sendFiscalDocumentStatusUpdated(FiscalDocument $fiscalDocument, string $oldStatus, string $newStatus): bool
    {
        return $this->sendStatusUpdateNotification(
            documentType: 'fiscal_document',
            companyId: (int) $fiscalDocument->company_id,
            partnerId: (int) $fiscalDocument->customer_id,
            documentNumber: (string) ($fiscalDocument->document_number ?: $fiscalDocument->id),
            oldStatus: $oldStatus,
            newStatus: $newStatus,
            partnerName: (string) ($fiscalDocument->customer?->name ?? ''),
            context: [
                'document_id' => $fiscalDocument->id,
            ],
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function sendStatusUpdateNotification(
        string $documentType,
        int $companyId,
        int $partnerId,
        string $documentNumber,
        string $oldStatus,
        string $newStatus,
        string $partnerName,
        array $context = [],
    ): bool {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! $this->shouldNotifyStatusUpdate($documentType, $newStatus, $companyId)) {
            return false;
        }

        [$subjectTemplate, $bodyTemplate] = $this->resolveStatusUpdateTemplates($documentType, $companyId);

        $replace = [
            '{{document_type}}' => $documentType,
            '{{document_number}}' => $documentNumber,
            '{{old_status}}' => $oldStatus,
            '{{new_status}}' => $newStatus,
            '{{partner_name}}' => $partnerName !== '' ? $partnerName : 'Cliente',
        ];

        $subject = strtr($subjectTemplate, $replace);
        $body = strtr($bodyTemplate, $replace);

        return $this->sendToCustomer(
            companyId: $companyId,
            partnerId: $partnerId,
            subject: $subject,
            bodyHtml: $body,
            attachments: [],
            context: array_merge($context, [
                'document_type' => $documentType,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]),
        );
    }

    /**
     * @param EmailAttachment[] $attachments
     * @param array<string,mixed> $context
     */
    private function sendToCustomer(
        int $companyId,
        int $partnerId,
        string $subject,
        string $bodyHtml,
        array $attachments,
        array $context = [],
    ): bool {
        $companyPartner = CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->with(['contacts' => function ($query) {
                $query->where('notify', true)
                    ->where('is_active', true)
                    ->whereNotNull('email');
            }])
            ->first();

        if (! $companyPartner) {
            Log::info('CustomerDocumentEmailService: parceiro não vinculado à empresa para envio de e-mail', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'context' => $context,
            ]);
            return false;
        }

        $to = $companyPartner->contacts
            ->pluck('email')
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        if ($to === []) {
            Log::info('CustomerDocumentEmailService: nenhum contato elegível para envio de e-mail', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'context' => $context,
            ]);
            return false;
        }

        $cc = $this->resolveCcList($companyId);
        $signature = (string) CompanyPreference::get('email_signature', $companyId, '');
        $companyName = $companyPartner->company?->name ?: (string) config('mail.from.name');
        $companyEmail = $companyPartner->company?->email ?: (string) config('mail.from.address');

        $finalHtml = $bodyHtml;
        if ($signature !== '') {
            $finalHtml .= '<br><br>' . nl2br(e($signature));
        }

        $message = new EmailMessage(
            to: $to,
            cc: $cc,
            subject: $subject,
            html: $finalHtml,
            text: strip_tags(str_replace('<br>', PHP_EOL, $finalHtml)),
            fromEmail: $companyEmail,
            fromName: $companyName,
            attachments: $attachments,
        );

        try {
            $this->emailProvider->send($message);

            Log::info('CustomerDocumentEmailService: e-mail enviado ao cliente', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'to' => $to,
                'cc' => $cc,
                'subject' => $subject,
                'context' => $context,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('CustomerDocumentEmailService: erro ao enviar e-mail ao cliente', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'subject' => $subject,
                'context' => $context,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return EmailAttachment[]
     */
    private function buildFiscalDocumentAttachments(FiscalDocument $fiscalDocument): array
    {
        $number = $fiscalDocument->document_number ?: (string) $fiscalDocument->id;

        if ($fiscalDocument->isNfse()) {
            if (! $fiscalDocument->isNfseAuthorized()) {
                return [];
            }

            $service = app(NfseDocumentService::class);
            $pdf = $service->pdf($fiscalDocument, 0);
            if ($pdf === null) {
                Log::warning('CustomerDocumentEmailService: falha ao gerar PDF NFS-e para envio', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'message' => $service->getMessage(),
                ]);
                return [];
            }

            return [
                new EmailAttachment(
                    filename: "nfse-{$number}.pdf",
                    contentBase64: $pdf,
                    mimeType: 'application/pdf',
                ),
            ];
        }

        if (! $fiscalDocument->isAuthorized()) {
            return [];
        }

        $service = app(NfeDocumentService::class);
        $danfe = $service->danfe($fiscalDocument, 0);
        if ($danfe === null) {
            Log::warning('CustomerDocumentEmailService: falha ao gerar DANFE para envio', [
                'fiscal_document_id' => $fiscalDocument->id,
                'message' => $service->getMessage(),
            ]);
            return [];
        }

        return [
            new EmailAttachment(
                filename: "danfe-{$number}.pdf",
                contentBase64: $danfe,
                mimeType: 'application/pdf',
            ),
        ];
    }


    private function shouldNotifyStatusUpdate(string $documentType, string $newStatus, int $companyId): bool
    {
        $config = CompanyPreference::get(
            CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_CONFIG_KEY,
            $companyId,
            CompanyPreference::getDefaultCustomerStatusNotificationConfig(),
        );

        if (! is_array($config)) {
            $config = CompanyPreference::getDefaultCustomerStatusNotificationConfig();
        }

        $entry = $config[$documentType] ?? null;

        if (! is_array($entry) || ! ($entry['enabled'] ?? false)) {
            return false;
        }

        $statuses = collect($entry['statuses'] ?? [])
            ->filter(fn ($status) => is_string($status) && trim($status) !== '')
            ->map(fn (string $status) => trim($status))
            ->values()
            ->all();

        return in_array($newStatus, $statuses, true);
    }

    /**
     * @return array{string,string}
     */
    private function resolveStatusUpdateTemplates(string $documentType, int $companyId): array
    {
        $defaults = CompanyPreference::getDefaultCustomerStatusNotificationTemplates();
        $templates = CompanyPreference::get(
            CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_TEMPLATES_KEY,
            $companyId,
            $defaults,
        );

        if (! is_array($templates)) {
            $templates = $defaults;
        }

        $template = $templates[$documentType] ?? $defaults[$documentType] ?? [
            'subject' => 'Atualização de status {{document_number}}',
            'body' => 'Olá {{partner_name}},<br><br>O documento {{document_number}} foi atualizado para {{new_status}}.',
        ];

        $subject = is_string($template['subject'] ?? null) ? $template['subject'] : 'Atualização de status {{document_number}}';
        $body = is_string($template['body'] ?? null) ? $template['body'] : 'Olá {{partner_name}},<br><br>O documento {{document_number}} foi atualizado para {{new_status}}.';

        return [$subject, $body];
    }

    /**
     * @return string[]
     */
    private function resolveCcList(int $companyId): array
    {
        $raw = CompanyPreference::get('email_cc', $companyId);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/[;,]+/', $raw) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function wasFiscalDocumentEmailSent(FiscalDocument $fiscalDocument): bool
    {
        $meta = $fiscalDocument->logs['customer_emails'] ?? null;

        return is_array($meta) && ! empty($meta['fiscal_document_authorized_sent_at']);
    }

    private function markFiscalDocumentEmailAsSent(FiscalDocument $fiscalDocument): void
    {
        $logs = $fiscalDocument->logs ?? [];
        if (! is_array($logs)) {
            $logs = [];
        }

        $logs['customer_emails'] = array_merge(
            is_array($logs['customer_emails'] ?? null) ? $logs['customer_emails'] : [],
            [
                'fiscal_document_authorized_sent_at' => now()->toDateTimeString(),
            ]
        );

        $fiscalDocument->update(['logs' => $logs]);
    }

    private function isEnabled(): bool
    {
        return (bool) config('email_notifications.enabled', true);
    }
}

