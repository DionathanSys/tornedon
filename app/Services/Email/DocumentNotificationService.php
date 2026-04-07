<?php

namespace App\Services\Email;

use App\Enums\AttachmentType;
use App\Enum\Email\DocumentNotificationEvent;
use App\Enum\Email\DocumentNotificationType;
use App\Enum\Email\EmailDispatchStatus;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Jobs\SendDocumentNotificationJob;
use App\Models\CompanyEmailPolicy;
use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use App\Models\EmailDispatch;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\DTO\EmailAttachment;
use App\Services\Email\DTO\EmailMessage;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\Actions\PrintInvoicePdfAction;
use App\Services\ProductionOrder\Actions\PrintProductionOrderPdfAction;
use App\Services\Requisition\Actions\PrintRequisitionPdfAction;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class DocumentNotificationService
{
    public function __construct(
        private readonly EmailProviderInterface $emailProvider,
    ) {}

    public function scheduleForServiceOrderStatusChange(ServiceOrder $serviceOrder, string $oldStatus, string $newStatus): ?EmailDispatch
    {
        if ($newStatus === ServiceOrderState::CLOSED->value) {
            return $this->schedule(
                documentType: DocumentNotificationType::SERVICE_ORDER,
                documentId: (int) $serviceOrder->id,
                companyId: (int) $serviceOrder->company_id,
                partnerId: (int) $serviceOrder->customer_id,
                event: DocumentNotificationEvent::CLOSED,
            );
        }

        if (
            $oldStatus === ServiceOrderState::CLOSED->value
            && $newStatus === ServiceOrderState::OPEN->value
        ) {
            return $this->schedule(
                documentType: DocumentNotificationType::SERVICE_ORDER,
                documentId: (int) $serviceOrder->id,
                companyId: (int) $serviceOrder->company_id,
                partnerId: (int) $serviceOrder->customer_id,
                event: DocumentNotificationEvent::REOPENED,
            );
        }

        if ($newStatus !== ServiceOrderState::CANCELLED->value) {
            return null;
        }

        return $this->schedule(
            documentType: DocumentNotificationType::SERVICE_ORDER,
            documentId: (int) $serviceOrder->id,
            companyId: (int) $serviceOrder->company_id,
            partnerId: (int) $serviceOrder->customer_id,
            event: DocumentNotificationEvent::CANCELLED,
        );
    }

    public function scheduleForRequisitionStatusChange(Requisition $requisition, string $oldStatus, string $newStatus): ?EmailDispatch
    {
        if ($newStatus === RequisitionStatus::CLOSED->value) {
            return $this->schedule(
                documentType: DocumentNotificationType::REQUISITION,
                documentId: (int) $requisition->id,
                companyId: (int) $requisition->company_id,
                partnerId: (int) $requisition->customer_id,
                event: DocumentNotificationEvent::CLOSED,
            );
        }

        if (
            in_array($oldStatus, [RequisitionStatus::CLOSED->value, RequisitionStatus::CANCELLED->value], true)
            && $newStatus === RequisitionStatus::OPEN->value
        ) {
            return $this->schedule(
                documentType: DocumentNotificationType::REQUISITION,
                documentId: (int) $requisition->id,
                companyId: (int) $requisition->company_id,
                partnerId: (int) $requisition->customer_id,
                event: DocumentNotificationEvent::REOPENED,
            );
        }

        if ($newStatus !== RequisitionStatus::CANCELLED->value) {
            return null;
        }

        return $this->schedule(
            documentType: DocumentNotificationType::REQUISITION,
            documentId: (int) $requisition->id,
            companyId: (int) $requisition->company_id,
            partnerId: (int) $requisition->customer_id,
            event: DocumentNotificationEvent::CANCELLED,
        );
    }

    public function scheduleForFiscalDocumentStatusChange(FiscalDocument $fiscalDocument, string $newStatus): ?EmailDispatch
    {
        if ($newStatus !== FiscalDocumentStatus::CONFIRMED->value) {
            return null;
        }

        return $this->schedule(
            documentType: DocumentNotificationType::FISCAL_DOCUMENT,
            documentId: (int) $fiscalDocument->id,
            companyId: (int) $fiscalDocument->company_id,
            partnerId: (int) $fiscalDocument->customer_id,
            event: DocumentNotificationEvent::CONFIRMED,
        );
    }

    public function scheduleForProductionOrderStatusChange(ProductionOrder $productionOrder, string $oldStatus, string $newStatus): ?EmailDispatch
    {
        if ($newStatus === \App\Enum\ProductionOrder\Status::COMPLETED->value) {
            return $this->schedule(
                documentType: DocumentNotificationType::PRODUCTION_ORDER,
                documentId: (int) $productionOrder->id,
                companyId: (int) $productionOrder->company_id,
                partnerId: (int) $productionOrder->customer_id,
                event: DocumentNotificationEvent::CLOSED,
            );
        }

        if ($oldStatus === $newStatus || $newStatus !== \App\Enum\ProductionOrder\Status::CANCELLED->value) {
            return null;
        }

        return $this->schedule(
            documentType: DocumentNotificationType::PRODUCTION_ORDER,
            documentId: (int) $productionOrder->id,
            companyId: (int) $productionOrder->company_id,
            partnerId: (int) $productionOrder->customer_id,
            event: DocumentNotificationEvent::CANCELLED,
        );
    }

    public function scheduleForInvoiceStatusChange(Invoice $invoice, string $newStatus): ?EmailDispatch
    {
        if ($newStatus !== \App\Enum\Invoice\Status::CONFIRMED->value) {
            return null;
        }

        return $this->schedule(
            documentType: DocumentNotificationType::INVOICE,
            documentId: (int) $invoice->id,
            companyId: (int) $invoice->company_id,
            partnerId: (int) $invoice->customer_id,
            event: DocumentNotificationEvent::CONFIRMED,
        );
    }

    public function requeue(EmailDispatch $dispatch): void
    {
        $dispatch->update([
            'status' => EmailDispatchStatus::PENDING,
            'error_message' => null,
        ]);

        SendDocumentNotificationJob::dispatch($dispatch->id)->afterCommit();
    }

    public function shouldSendForServiceOrder(ServiceOrder $serviceOrder): bool
    {
        return $this->shouldSend(
            documentType: DocumentNotificationType::SERVICE_ORDER,
            companyId: (int) $serviceOrder->company_id,
            partnerId: (int) $serviceOrder->customer_id,
            event: DocumentNotificationEvent::CLOSED,
        );
    }

    public function shouldSendForRequisition(Requisition $requisition): bool
    {
        return $this->shouldSend(
            documentType: DocumentNotificationType::REQUISITION,
            companyId: (int) $requisition->company_id,
            partnerId: (int) $requisition->customer_id,
            event: DocumentNotificationEvent::CLOSED,
        );
    }

    public function processDispatch(EmailDispatch $dispatch, int $attempt): void
    {
        if ($dispatch->status === EmailDispatchStatus::SENT || $dispatch->status === EmailDispatchStatus::CANCELLED) {
            return;
        }

        $companyPartner = CompanyPartner::query()
            ->with(['contacts', 'company', 'partner'])
            ->find($dispatch->company_partner_id);

        if (! $companyPartner || ! $companyPartner->is_active) {
            $this->markCancelled($dispatch, 'CompanyPartner ausente/inativo para envio.');
            return;
        }

        $policy = CompanyEmailPolicy::resolve(
            companyId: (int) $dispatch->company_id,
            documentType: $dispatch->document_type,
            event: $dispatch->event,
        );

        if (! $policy->enabled) {
            $this->markCancelled($dispatch, 'Política de e-mail desabilitada para documento/evento.');
            return;
        }

        if (! $this->isPartnerNotifiable($companyPartner, $dispatch->document_type)) {
            $this->markCancelled($dispatch, 'CompanyPartner sem notificação habilitada para este tipo.');
            return;
        }

        $document = $this->findDocument($dispatch->document_type, (int) $dispatch->document_id);
        if ($document === null) {
            $this->markCancelled($dispatch, 'Documento não encontrado para o dispatch.');
            return;
        }

        [$to, $cc, $bcc] = $this->resolveRecipients($companyPartner, $policy);
        if ($to === []) {
            $this->markCancelled($dispatch, 'Nenhum destinatário TO válido encontrado.');
            return;
        }

        $context = $this->buildTemplateContext($document, $dispatch->document_type, $dispatch->event, $companyPartner);
        $subject = $this->renderTemplate((string) $policy->subject_template, $context);
        $body = $this->renderTemplate((string) $policy->body_template, $context);

        $signature = (string) CompanyPreference::get('email_signature', (int) $dispatch->company_id, '');
        if ($signature !== '') {
            $body .= '<br><br>' . nl2br(e($signature));
        }

        $dispatch->update([
            'status' => EmailDispatchStatus::PROCESSING,
            'attempts' => max((int) $dispatch->attempts, $attempt),
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'rendered_subject' => $subject,
            'rendered_body' => $body,
            'provider' => (string) config('email_notifications.provider', 'resend'),
        ]);

        [$attachments, $manifest, $attachmentNotes] = $this->resolveAttachments(
            dispatch: $dispatch,
            policy: $policy,
            document: $document,
            documentType: $dispatch->document_type,
        );

        if ($attachmentNotes !== []) {
            $body .= '<br><br><strong>Links de anexos:</strong><br>' . implode('<br>', $attachmentNotes);
        }

        $attachmentsHash = hash('sha256', json_encode($manifest) ?: '');
        $dispatch->update([
            'attachments_manifest' => $manifest,
            'attachments_hash' => $attachmentsHash,
        ]);

        $fromEmail = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');

        $message = new EmailMessage(
            to: $to,
            cc: $cc,
            subject: $subject,
            html: $body,
            text: strip_tags(str_replace('<br>', PHP_EOL, $body)),
            fromEmail: $fromEmail,
            fromName: $fromName,
            attachments: $attachments,
        );

        try {
            $providerResult = $this->emailProvider->send($message);

            $dispatch->update([
                'status' => EmailDispatchStatus::SENT,
                'provider_message_id' => $providerResult['provider_message_id'] ?? null,
                'provider_payload' => $providerResult['provider_payload'] ?? null,
                'sent_at' => now(),
                'error_message' => null,
                'last_error_at' => null,
                'attempts' => max((int) $dispatch->attempts, $attempt),
            ]);
        } catch (\Throwable $e) {
            $dispatch->update([
                'status' => EmailDispatchStatus::FAILED,
                'error_message' => $e->getMessage(),
                'last_error_at' => now(),
                'attempts' => max((int) $dispatch->attempts, $attempt),
            ]);

            throw $e;
        }
    }

    private function schedule(
        DocumentNotificationType $documentType,
        int $documentId,
        int $companyId,
        int $partnerId,
        DocumentNotificationEvent $event,
    ): ?EmailDispatch {
        if (! (bool) config('email_notifications.enabled', true)) {
            return null;
        }

        $companyPartner = CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->with(['contacts' => function ($query) {
                $query->where('notify', true)
                    ->where('is_active', true)
                    ->whereNotNull('email');
            }])
            ->first();

        if (! $companyPartner || ! $companyPartner->is_active) {
            return null;
        }

        if (! $this->isPartnerNotifiable($companyPartner, $documentType)) {
            return null;
        }

        $policy = CompanyEmailPolicy::resolve($companyId, $documentType, $event);
        if (! $policy->enabled) {
            return null;
        }

        [$to, $cc, $bcc] = $this->resolveRecipients($companyPartner, $policy);
        if ($to === []) {
            return null;
        }

        $idempotencyKey = $this->buildIdempotencyKey($companyId, $documentType, $documentId, $event);

        try {
            $dispatch = EmailDispatch::query()->create([
                'company_id' => $companyId,
                'document_type' => $documentType->value,
                'document_id' => $documentId,
                'event' => $event->value,
                'company_partner_id' => $companyPartner->id,
                'status' => EmailDispatchStatus::PENDING->value,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'idempotency_key' => $idempotencyKey,
                'max_attempts' => 5,
                'provider' => (string) config('email_notifications.provider', 'resend'),
            ]);
        } catch (QueryException $e) {
            Log::warning('DocumentNotificationService: falha ao criar dispatch', [
                'company_id' => $companyId,
                'document_type' => $documentType->value,
                'document_id' => $documentId,
                'event' => $event->value,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        SendDocumentNotificationJob::dispatch($dispatch->id)->afterCommit();

        return $dispatch;
    }

    private function shouldSend(
        DocumentNotificationType $documentType,
        int $companyId,
        int $partnerId,
        DocumentNotificationEvent $event,
    ): bool {
        if (! (bool) config('email_notifications.enabled', true)) {
            return false;
        }

        $companyPartner = CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->with(['contacts' => function ($query) {
                $query->where('notify', true)
                    ->where('is_active', true)
                    ->whereNotNull('email');
            }])
            ->first();

        Log::debug('CompanyPartner', [
            'company_partner' => $companyPartner,
        ]);

        if (! $companyPartner || ! $companyPartner->is_active) {
            return false;
        }

        if (! $this->isPartnerNotifiable($companyPartner, $documentType)) {
            return false;
        }

        $policy = CompanyEmailPolicy::resolve($companyId, $documentType, $event);
        if (! $policy->enabled) {
            return false;
        }

        Log::debug('Policy', [
            'policy' => $policy,
        ]);

        [$to] = $this->resolveRecipients($companyPartner, $policy);

        Log::debug('Recipients', [
            'to' => $to,
        ]);

        return $to !== [];
    }

    private function isPartnerNotifiable(CompanyPartner $companyPartner, DocumentNotificationType $documentType): bool
    {
        return match ($documentType) {
            DocumentNotificationType::SERVICE_ORDER => (bool) $companyPartner->notify_service_order_closed,
            DocumentNotificationType::REQUISITION => (bool) $companyPartner->notify_requisition_closed,
            DocumentNotificationType::PRODUCTION_ORDER => (bool) $companyPartner->notify_production_order_closed,
            DocumentNotificationType::INVOICE => (bool) $companyPartner->notify_invoice_confirmed,
            DocumentNotificationType::FISCAL_DOCUMENT => (bool) $companyPartner->notify_fiscal_document_confirmed,
        };
    }

    /**
     * @return array{string[],string[],string[]}
     */
    private function resolveRecipients(CompanyPartner $companyPartner, CompanyEmailPolicy $policy): array
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
     * @return array<int,array<string,mixed>>
     */
    private function buildAttachmentRegistry(DocumentNotificationType $documentType, mixed $document): array
    {
        return match ($documentType) {
            DocumentNotificationType::SERVICE_ORDER => $this->buildServiceOrderAttachmentRegistry($document),
            DocumentNotificationType::REQUISITION => $this->buildRequisitionAttachmentRegistry($document),
            DocumentNotificationType::PRODUCTION_ORDER => $this->buildProductionOrderAttachmentRegistry($document),
            DocumentNotificationType::INVOICE => $this->buildInvoiceAttachmentRegistry($document),
            DocumentNotificationType::FISCAL_DOCUMENT => $this->buildFiscalAttachmentRegistry($document),
        };
    }

    /**
     * @return array<int,EmailAttachment>|array<int,array<string,mixed>>|array<int,string>
     */
    private function resolveAttachments(
        EmailDispatch $dispatch,
        CompanyEmailPolicy $policy,
        mixed $document,
        DocumentNotificationType $documentType,
    ): array {
        $requiredKinds = collect($policy->required_attachments ?? [])
            ->map(fn ($item): string => Str::lower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values();

        $optionalKinds = collect($policy->optional_attachments ?? [])
            ->map(fn ($item): string => Str::lower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values();

        $registry = collect($this->buildAttachmentRegistry($documentType, $document))
            ->keyBy(fn (array $entry): string => (string) ($entry['kind'] ?? ''));

        $candidateKinds = $requiredKinds->merge($optionalKinds)->unique()->values();

        $rawAttachments = [];
        foreach ($candidateKinds as $kind) {
            $entry = $registry->get($kind);
            $isOptional = $optionalKinds->contains($kind);

            if (! is_array($entry) || ! ($entry['attachment'] ?? null) instanceof EmailAttachment) {
                if (! $isOptional) {
                    throw new \RuntimeException("Anexo obrigatório '{$kind}' não pôde ser gerado.");
                }

                continue;
            }

            /** @var EmailAttachment $attachment */
            $attachment = $entry['attachment'];
            $rawAttachments[] = new EmailAttachment(
                filename: $attachment->filename,
                contentBase64: $attachment->contentBase64,
                mimeType: $attachment->mimeType,
                optional: $isOptional,
                kind: $kind,
            );
        }

        $maxBytes = max(1, (int) $policy->max_total_size_mb) * 1024 * 1024;
        $allowedMimes = collect($policy->allowed_mime_types ?? [])
            ->map(fn ($mime): string => Str::lower(trim((string) $mime)))
            ->filter()
            ->values()
            ->all();
        if ($allowedMimes === []) {
            $allowedMimes = ['application/pdf', 'application/xml', 'text/xml', 'text/plain'];
        }

        $attachments = [];
        $manifest = [];
        $notes = [];
        $totalSize = 0;
        $fallbackMode = Str::lower((string) ($policy->fallback_mode ?? 'signed_link'));

        foreach ($rawAttachments as $attachment) {
            $binary = base64_decode($attachment->contentBase64, true);
            if ($binary === false) {
                if ($attachment->optional) {
                    continue;
                }

                throw new \RuntimeException("Conteúdo inválido para anexo obrigatório '{$attachment->filename}'.");
            }

            $mime = Str::lower((string) $attachment->mimeType);
            $size = strlen($binary);
            $sha256 = hash('sha256', $binary);

            $mimeAllowed = in_array($mime, $allowedMimes, true);
            $fitsLimit = ($totalSize + $size) <= $maxBytes;

            if ($mimeAllowed && $fitsLimit) {
                $attachments[] = $attachment;
                $totalSize += $size;

                $manifest[] = [
                    'kind' => $attachment->kind,
                    'filename' => $attachment->filename,
                    'mime' => $mime,
                    'size' => $size,
                    'sha256' => $sha256,
                    'mode' => 'attachment',
                ];

                continue;
            }

            if (! $attachment->optional) {
                throw new \RuntimeException("Anexo obrigatório '{$attachment->filename}' excede política de MIME/tamanho.");
            }

            if ($fallbackMode === 'fail') {
                throw new \RuntimeException("Anexo opcional '{$attachment->filename}' bloqueou o envio pela política de fallback=fail.");
            }

            if ($fallbackMode !== 'signed_link') {
                continue;
            }

            $token = Str::random(40);
            $extension = pathinfo($attachment->filename, PATHINFO_EXTENSION);
            $safeName = Str::slug(pathinfo($attachment->filename, PATHINFO_FILENAME));
            $safeName = $safeName !== '' ? $safeName : 'arquivo';
            $storedFilename = $extension !== '' ? "{$token}-{$safeName}.{$extension}" : "{$token}-{$safeName}";
            $path = "private/email-dispatches/{$dispatch->id}/{$storedFilename}";

            Storage::disk('local')->put($path, $binary);

            $url = URL::temporarySignedRoute(
                'email-dispatch.attachment',
                now()->addDays(7),
                ['emailDispatch' => $dispatch->id, 'token' => $token],
            );

            $notes[] = e($attachment->filename) . ': <a href="' . e($url) . '">' . e($url) . '</a>';

            $manifest[] = [
                'kind' => $attachment->kind,
                'filename' => $attachment->filename,
                'mime' => $mime,
                'size' => $size,
                'sha256' => $sha256,
                'mode' => 'signed_link',
                'token' => $token,
                'path' => $path,
                'url_expires_at' => now()->addDays(7)->toDateTimeString(),
            ];
        }

        return [$attachments, $manifest, $notes];
    }

    /**
     * @return array<int,array{kind:string,attachment:EmailAttachment|null}>
     */
    private function buildServiceOrderAttachmentRegistry(ServiceOrder $serviceOrder): array
    {
        $pdfAction = app(PrintServiceOrderPdfAction::class);
        $pdf = $pdfAction->execute($serviceOrder);
        $number = $serviceOrder->number ?: (string) $serviceOrder->id;

        return [
            [
                'kind' => 'pdf',
                'attachment' => is_string($pdf) && ! $pdfAction->hasError()
                    ? new EmailAttachment(
                        filename: "ordem-servico-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'pdf',
                    )
                    : null,
            ],
        ];
    }

    /**
     * @return array<int,array{kind:string,attachment:EmailAttachment|null}>
     */
    private function buildRequisitionAttachmentRegistry(Requisition $requisition): array
    {
        $pdfAction = new PrintRequisitionPdfAction();
        $pdf = $pdfAction->execute($requisition);
        $number = $requisition->number ?: (string) $requisition->id;

        return [
            [
                'kind' => 'pdf',
                'attachment' => is_string($pdf) && ! $pdfAction->hasError()
                    ? new EmailAttachment(
                        filename: "requisicao-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'pdf',
                    )
                    : null,
            ],
        ];
    }

    /**
     * @return array<int,array{kind:string,attachment:EmailAttachment|null}>
     */
    private function buildProductionOrderAttachmentRegistry(ProductionOrder $productionOrder): array
    {
        $pdfAction = new PrintProductionOrderPdfAction();
        $pdf = $pdfAction->execute($productionOrder);
        $number = $productionOrder->production_order_number ?: (string) $productionOrder->id;

        return [
            [
                'kind' => 'pdf',
                'attachment' => is_string($pdf) && ! $pdfAction->hasError()
                    ? new EmailAttachment(
                        filename: "ordem-producao-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'pdf',
                    )
                    : null,
            ],
        ];
    }

    /**
     * @return array<int,array{kind:string,attachment:EmailAttachment|null}>
     */
    private function buildInvoiceAttachmentRegistry(Invoice $invoice): array
    {
        $pdfAction = app(PrintInvoicePdfAction::class);
        $pdf = $pdfAction->execute($invoice);
        $number = $invoice->invoice_number ?: (string) $invoice->id;

        return [
            [
                'kind' => 'pdf',
                'attachment' => is_string($pdf) && ! $pdfAction->hasError()
                    ? new EmailAttachment(
                        filename: "fatura-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'pdf',
                    )
                    : null,
            ],
        ];
    }

    /**
     * @return array<int,array{kind:string,attachment:EmailAttachment|null}>
     */
    private function buildFiscalAttachmentRegistry(FiscalDocument $fiscalDocument): array
    {
        $number = $fiscalDocument->document_number ?: (string) $fiscalDocument->id;
        $persisted = $this->buildPersistedFiscalAttachments($fiscalDocument, $number);

        $danfeAttachment = $persisted['danfe'] ?? null;
        $xmlAttachment = $persisted['xml'] ?? null;

        if (! $danfeAttachment) {
            if ($fiscalDocument->isNfse()) {
                $service = app(NfseDocumentService::class);
                $pdf = $service->pdf($fiscalDocument, 0);
                $danfeAttachment = is_string($pdf)
                    ? new EmailAttachment(
                        filename: "nfse-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'danfe',
                    )
                    : null;
            } else {
                $service = app(NfeDocumentService::class);
                $pdf = $service->danfe($fiscalDocument, 0);
                $danfeAttachment = is_string($pdf)
                    ? new EmailAttachment(
                        filename: "danfe-{$number}.pdf",
                        contentBase64: $pdf,
                        mimeType: 'application/pdf',
                        kind: 'danfe',
                    )
                    : null;
            }
        }

        if (! $xmlAttachment) {
            $xmlBase64 = $this->extractFiscalXmlBase64($fiscalDocument);
            $xmlAttachment = $xmlBase64 !== null
                ? new EmailAttachment(
                    filename: "nf-{$number}.xml",
                    contentBase64: $xmlBase64,
                    mimeType: 'application/xml',
                    kind: 'xml',
                )
                : null;
        }

        return [
            [
                'kind' => 'danfe',
                'attachment' => $danfeAttachment,
            ],
            [
                'kind' => 'xml',
                'attachment' => $xmlAttachment,
            ],
        ];
    }

    /**
     * @return array{danfe:EmailAttachment|null,xml:EmailAttachment|null}
     */
    private function buildPersistedFiscalAttachments(FiscalDocument $fiscalDocument, string $number): array
    {
        if (! method_exists($fiscalDocument, 'attachmentsOfType')) {
            return ['danfe' => null, 'xml' => null];
        }

        $attachments = $fiscalDocument->attachmentsOfType(AttachmentType::FISCAL_DOCUMENT)
            ->where('is_current', true)
            ->orderByDesc('version')
            ->get();

        $resolveByKind = function (string $kind) use ($attachments, $number): ?EmailAttachment {
            $record = $attachments->first(function ($attachment) use ($kind): bool {
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
                filename: (string) ($record->original_name ?: "nf-{$number}"),
                contentBase64: base64_encode($binary),
                mimeType: (string) ($record->mime_type ?: 'application/octet-stream'),
                kind: $kind,
            );
        };

        return [
            'danfe' => $resolveByKind('danfe'),
            'xml' => $resolveByKind('xml'),
        ];
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

    /**
     * @return array<string,string>
     */
    private function buildTemplateContext(
        mixed $document,
        DocumentNotificationType $documentType,
        DocumentNotificationEvent $event,
        CompanyPartner $companyPartner,
    ): array {
        return [
            '{{partner_name}}' => (string) ($companyPartner->partner?->name ?: 'Cliente'),
            '{{document_number}}' => $this->resolveDocumentNumber($document, $documentType),
            '{{document_type}}' => $documentType->description(),
            '{{event_name}}' => $event->description(),
        ];
    }

    private function renderTemplate(string $template, array $context): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = 'Documento {{document_number}} atualizado';
        }

        return strtr($template, $context);
    }

    private function resolveDocumentNumber(mixed $document, DocumentNotificationType $documentType): string
    {
        return match ($documentType) {
            DocumentNotificationType::SERVICE_ORDER => (string) ($document->number ?: $document->id),
            DocumentNotificationType::REQUISITION => (string) ($document->number ?: $document->id),
            DocumentNotificationType::PRODUCTION_ORDER => (string) ($document->production_order_number ?: $document->id),
            DocumentNotificationType::INVOICE => (string) ($document->invoice_number ?: $document->id),
            DocumentNotificationType::FISCAL_DOCUMENT => (string) ($document->document_number ?: $document->id),
        };
    }

    private function findDocument(DocumentNotificationType $documentType, int $documentId): ServiceOrder|Requisition|ProductionOrder|Invoice|FiscalDocument|null
    {
        return match ($documentType) {
            DocumentNotificationType::SERVICE_ORDER => ServiceOrder::query()->with(['customer', 'company'])->find($documentId),
            DocumentNotificationType::REQUISITION => Requisition::query()->with(['customer', 'company'])->find($documentId),
            DocumentNotificationType::PRODUCTION_ORDER => ProductionOrder::query()->with(['customer', 'company'])->find($documentId),
            DocumentNotificationType::INVOICE => Invoice::query()->with(['customer', 'company'])->find($documentId),
            DocumentNotificationType::FISCAL_DOCUMENT => FiscalDocument::query()->with(['customer', 'company'])->find($documentId),
        };
    }

    private function buildIdempotencyKey(
        int $companyId,
        DocumentNotificationType $documentType,
        int $documentId,
        DocumentNotificationEvent $event,
    ): string {
        $now = Carbon::now()->format('YmdHis.u');

        return hash('sha256', implode(':', [
            $companyId,
            $documentType->value,
            $documentId,
            $event->value,
            Str::uuid()->toString(),
            $now,
        ]));
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

    private function markCancelled(EmailDispatch $dispatch, string $reason): void
    {
        $dispatch->update([
            'status' => EmailDispatchStatus::CANCELLED,
            'error_message' => $reason,
            'last_error_at' => now(),
        ]);
    }
}
