<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentItemOrigin extends Model
{
    protected $fillable = [
        'origin_fiscal_document_id',
        'origin_fiscal_document_item_id',
        'return_fiscal_document_id',
        'return_fiscal_document_item_id',
        'linked_quantity',
        'linked_value',
        'origin_document_key',
        'metadata',
    ];

    protected $casts = [
        'linked_quantity' => 'decimal:4',
        'linked_value' => MoneyCast::class,
        'metadata' => 'array',
    ];

    public function originDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'origin_fiscal_document_id');
    }

    public function originItem(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentItem::class, 'origin_fiscal_document_item_id');
    }

    public function returnDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'return_fiscal_document_id');
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentItem::class, 'return_fiscal_document_item_id');
    }
}
