<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalStockEffect extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['stock_control_enabled' => 'boolean', 'base_quantity' => 'decimal:8', 'snapshot' => 'array', 'processed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $effect): void {
            if ($effect->getRawOriginal('processed_at') !== null && $effect->isDirty()) {
                throw new \LogicException('O efeito de estoque de uma NF autorizada é imutável.');
            }
        });
        static::deleting(function (self $effect): void {
            if ($effect->processed_at) {
                throw new \LogicException('O histórico da saída fiscal deve ser preservado.');
            }
        });
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function fiscalDocumentItem(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentItem::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
