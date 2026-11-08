<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class InvoiceConfirmation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amounts' => 'array', 'commercial_data' => 'array', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O retrato de confirmação da fatura é imutável.'));
        static::deleting(fn () => throw new LogicException('O histórico de confirmação da fatura não pode ser excluído.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(InvoiceSource::class);
    }
}
