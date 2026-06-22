<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryServiceOrderMigrationLink extends Model
{
    protected $fillable = [
        'company_id',
        'legacy_id',
        'legacy_partner_id',
        'legacy_equipment_id',
        'legacy_invoice_id',
        'service_order_id',
        'customer_partner_id',
        'equipment_id',
        'legacy_updated_at',
        'payload',
        'last_imported_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'legacy_updated_at' => 'datetime',
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
}
