<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSlipEvent extends Model
{
    protected $fillable = [
        'company_id',
        'bank_account_connection_id',
        'billing_provider_id',
        'bank_slip_id',
        'provider_event_id',
        'event_type',
        'provider_status_code',
        'amount',
        'payload',
        'received_at',
        'processed_at',
        'failed_at',
        'error',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankAccountConnection::class, 'bank_account_connection_id');
    }

    public function billingProvider(): BelongsTo
    {
        return $this->belongsTo(BillingProvider::class);
    }

    public function bankSlip(): BelongsTo
    {
        return $this->belongsTo(BankSlip::class);
    }
}
