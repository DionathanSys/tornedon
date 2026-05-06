<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfeInvalidationRequest extends Model
{
    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'serie',
        'number_start',
        'number_end',
        'justification',
        'status',
        'requested_by',
        'processed_by',
        'processed_at',
        'response_payload',
        'error_message',
    ];

    protected $casts = [
        'number_start' => 'integer',
        'number_end' => 'integer',
        'processed_at' => 'datetime',
        'response_payload' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
