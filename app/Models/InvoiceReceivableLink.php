<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceReceivableLink extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['original_links' => 'array', 'snapshot' => 'array', 'release_snapshot' => 'array', 'linked_at' => 'datetime', 'released_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            if ($link->isDirty(['company_id', 'invoice_id', 'account_receivable_id', 'source_receivable_id', 'origin', 'original_links', 'snapshot', 'linked_at', 'linked_by']) || ($link->getRawOriginal('released_at') !== null && $link->isDirty())) {
                throw new LogicException('O histórico do vínculo financeiro é imutável.');
            }
        });
        static::deleting(fn () => throw new LogicException('O histórico financeiro da fatura deve ser preservado.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }
}
