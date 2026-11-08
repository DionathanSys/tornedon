<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceSource extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'linked_at' => 'datetime', 'released_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            if ($source->isDirty(['company_id', 'invoice_id', 'invoice_confirmation_id', 'source_type', 'source_id', 'source_number', 'snapshot', 'linked_at'])) {
                throw new LogicException('A composição histórica da fatura é imutável.');
            }
        });
        static::deleting(fn () => throw new LogicException('A composição histórica da fatura não pode ser excluída.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(InvoiceConfirmation::class, 'invoice_confirmation_id');
    }
}
