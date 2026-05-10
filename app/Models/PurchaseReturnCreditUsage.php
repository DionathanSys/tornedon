<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnCreditUsage extends Model
{
    protected $fillable = [
        'purchase_return_credit_id',
        'partner_id',
        'fiscal_document_id',
        'account_payable_id',
        'amount_used',
        'used_at',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount_used' => MoneyCast::class,
        'used_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnCredit::class, 'purchase_return_credit_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }
}
