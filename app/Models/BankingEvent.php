<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankingEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'last_received_at' => 'datetime', 'processed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankAccountConnection::class, 'bank_account_connection_id');
    }

    public function bankSlip(): BelongsTo
    {
        return $this->belongsTo(BankSlip::class);
    }

    public function pixCharge(): BelongsTo
    {
        return $this->belongsTo(PixCharge::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where(fn ($query) => $query->whereIn('status', ['pending', 'failed', 'needs_review'])
            ->orWhereHas('bankSlip', fn ($charge) => $charge->whereIn('status', ['processing', 'uncertain', 'registration_failed', 'cancel_pending', 'update_pending', 'needs_review']))
            ->orWhereHas('pixCharge', fn ($charge) => $charge->whereIn('status', ['processing', 'uncertain', 'registration_failed', 'needs_review'])));
    }
}
