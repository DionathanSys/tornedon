<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class HrReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw ValidationException::withMessages(['receipt' => 'Recibos emitidos são imutáveis e preservados no histórico.']);
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(HrEntry::class, 'hr_entry_id');
    }
}
