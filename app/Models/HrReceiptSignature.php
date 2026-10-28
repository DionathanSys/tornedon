<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class HrReceiptSignature extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw ValidationException::withMessages(['signature' => 'A assinatura do recibo é preservada no histórico e não pode ser substituída.']);
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(HrReceipt::class, 'hr_receipt_id');
    }
}
