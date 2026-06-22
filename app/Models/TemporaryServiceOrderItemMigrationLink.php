<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryServiceOrderItemMigrationLink extends Model
{
    protected $fillable = [
        'company_id',
        'legacy_id',
        'legacy_service_order_id',
        'legacy_service_id',
        'service_order_id',
        'service_order_item_id',
        'service_id',
        'payload',
        'last_imported_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_imported_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function serviceOrderItem(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderItem::class);
    }
}
