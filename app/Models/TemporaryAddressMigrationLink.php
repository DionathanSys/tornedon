<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryAddressMigrationLink extends Model
{
    protected $fillable = [
        'company_id',
        'legacy_id',
        'legacy_partner_id',
        'address_id',
        'company_partner_id',
        'fingerprint',
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

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function companyPartner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class);
    }
}
