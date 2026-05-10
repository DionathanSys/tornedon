<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\PurchaseReturnCredit\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturnCredit extends Model
{
    protected $fillable = [
        'company_id',
        'partner_id',
        'origin_fiscal_document_id',
        'return_fiscal_document_id',
        'amount',
        'used_amount',
        'status',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'used_amount' => MoneyCast::class,
        'status' => Status::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'available_amount',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function originFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'origin_fiscal_document_id');
    }

    public function returnFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'return_fiscal_document_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PurchaseReturnCreditUsage::class);
    }

    public function getAvailableAmountAttribute(): float
    {
        return max((float) $this->amount - (float) $this->used_amount, 0);
    }
}
