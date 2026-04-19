<?php

namespace App\Models;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SefazDistributionDocument extends Model
{
    protected $fillable = [
        'company_id',
        'partner_id',
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
        'summary_xml_path',
        'full_xml_path',
        'raw_response_path',
        'items_json',
        'distribution_payload',
        'import_ready_at',
        'imported_at',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => Status::class,
        'manifestation_status' => ManifestationStatus::class,
        'full_xml_available' => 'boolean',
        'items_json' => 'array',
        'distribution_payload' => 'array',
        'issued_at' => 'datetime',
        'import_ready_at' => 'datetime',
        'imported_at' => 'datetime',
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
}
