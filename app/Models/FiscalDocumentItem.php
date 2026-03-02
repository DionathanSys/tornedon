<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentItem extends Model
{
    protected $fillable = [
        'fiscal_document_id',
        'product_id',
        'item_number',
        'origin_code',
        'ncm_code',
        'cfop_code',
        'quantity',
        'unit_of_measure',
        'unit_price',
        'total_price',
        'included_in_total',
        'tax_data',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'item_number' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => MoneyCast::class,
        'total_price' => MoneyCast::class,
        'included_in_total' => 'boolean',
        'tax_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
