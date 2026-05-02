<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseClosingFiscalDocument extends Model
{
    protected $fillable = [
        'purchase_closing_id',
        'fiscal_document_id',
        'document_amount',
        'discount_amount',
    ];

    protected $casts = [
        'document_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function purchaseClosing(): BelongsTo
    {
        return $this->belongsTo(PurchaseClosing::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function getNetAmountAttribute(): float
    {
        return round((float) $this->document_amount - (float) $this->discount_amount, 2);
    }
}
