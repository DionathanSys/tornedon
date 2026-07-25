<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentPayload extends Model
{
    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'nfe_payload',
        'nfse_payload',
    ];

    protected $casts = [
        'nfe_payload' => 'array',
        'nfse_payload' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
