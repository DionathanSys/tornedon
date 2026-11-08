<?php

namespace App\Models;

use App\Enum\Invoice\OperationStatus;
use App\Enum\Invoice\OperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InvoiceOperation extends Model
{
    protected $guarded = ['id', 'active_slot'];

    protected $casts = ['type' => OperationType::class, 'status' => OperationStatus::class];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            if (! Invoice::whereKey($operation->invoice_id)->where('company_id', $operation->company_id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'A operação deve pertencer à empresa da fatura.']);
            }
            $operation->active_slot = $operation->status->isActive() ? 1 : null;
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
