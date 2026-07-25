<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentTaxDetail extends Model
{
    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'freight_data',
        'payment_data',
        'tax_data',
    ];

    protected $casts = [
        'freight_data' => 'array',
        'payment_data' => 'array',
        'tax_data' => 'array',
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
