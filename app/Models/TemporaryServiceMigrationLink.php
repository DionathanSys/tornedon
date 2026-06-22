<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryServiceMigrationLink extends Model
{
    protected $fillable = [
        'company_id',
        'legacy_id',
        'service_id',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
