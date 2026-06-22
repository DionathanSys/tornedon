<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryEquipmentMigrationLink extends Model
{
    protected $fillable = [
        'company_id',
        'legacy_id',
        'legacy_partner_id',
        'equipment_id',
        'owner_partner_id',
        'fingerprint',
        'legacy_updated_at',
        'legacy_deleted_at',
        'payload',
        'last_imported_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'legacy_updated_at' => 'datetime',
        'legacy_deleted_at' => 'datetime',
        'last_imported_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function ownerPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'owner_partner_id');
    }
}
