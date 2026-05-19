<?php

namespace App\Models;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SefazDistributionDocument extends Model
{
    protected $fillable = [
        'company_id',
        'partner_id',
        'fiscal_document_id',
        'document_key',
        'nsu',
        'schema',
        'document_type',
        'issuer_document',
        'issuer_name',
        'document_number',
        'document_series',
        'issued_at',
        'total_amount',
        'status',
        'manifestation_status',
        'full_xml_available',
        'import_status',
        'import_error',
        'import_attempted_at',
        'summary_xml_path',
        'full_xml_path',
        'raw_response_path',
        'items_json',
        'distribution_payload',
        'import_ready_at',
        'imported_at',
        'imported_by',
        'ignored_at',
        'ignored_by',
        'ignore_reason',
        'last_action',
        'last_action_at',
        'last_error_code',
        'last_error_message',
        'last_job_uuid',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => Status::class,
        'manifestation_status' => ManifestationStatus::class,
        'import_status' => ImportStatus::class,
        'full_xml_available' => 'boolean',
        'items_json' => 'array',
        'distribution_payload' => 'array',
        'issued_at' => 'datetime',
        'import_attempted_at' => 'datetime',
        'import_ready_at' => 'datetime',
        'imported_at' => 'datetime',
        'ignored_at' => 'datetime',
        'last_action_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }

    public function auditEntries(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable');
    }

    public function companyPartner(): HasOne
    {
        return $this->hasOne(CompanyPartner::class, 'partner_id', 'partner_id')
            ->where('company_partner.company_id', $this->company_id);
    }
}
