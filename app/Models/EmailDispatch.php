<?php

namespace App\Models;

use App\Enum\Email\DocumentNotificationEvent;
use App\Enum\Email\DocumentNotificationType;
use App\Enum\Email\EmailDispatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailDispatch extends Model
{
    protected $fillable = [
        'company_id',
        'company_partner_id',
        'document_type',
        'document_id',
        'event',
        'status',
        'to',
        'cc',
        'bcc',
        'subject',
        'rendered_subject',
        'rendered_body',
        'attachments_manifest',
        'attachments_hash',
        'idempotency_key',
        'attempts',
        'max_attempts',
        'provider',
        'provider_message_id',
        'provider_payload',
        'error_message',
        'last_error_at',
        'sent_at',
    ];

    protected $casts = [
        'document_type' => DocumentNotificationType::class,
        'event' => DocumentNotificationEvent::class,
        'status' => EmailDispatchStatus::class,
        'to' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
        'attachments_manifest' => 'array',
        'provider_payload' => 'array',
        'last_error_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPartner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class);
    }
}
