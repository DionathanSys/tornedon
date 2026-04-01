<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RemittanceAsset extends Model
{
    protected $fillable = [
        'company_id',
        'fiscal_document_id',
        'fiscal_document_item_id',
        'product_id',
        'equipment_id',
        'serial_number',
        'lot_number',
        'received_quantity',
        'returned_quantity',
        'status',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'received_quantity' => 'decimal:4',
        'returned_quantity' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function fiscalDocumentItem(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function serviceOrders(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceOrder::class,
            'service_order_received_assets'
        )
            ->withPivot(['quantity_allocated', 'notes'])
            ->withTimestamps();
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
