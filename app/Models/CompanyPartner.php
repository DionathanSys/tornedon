<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CompanyPartner extends Model
{
    protected $table = 'company_partner';

    protected $fillable = [
        'partner_id',
        'company_id',
        'type',
        'invoice_threshold',
        'is_active',
    ];

    protected $casts = [
        'invoice_threshold' => MoneyCast::class,
        'type'              => 'array',
        'is_active'         => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'company_partner_id', 'id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_partner_id', 'id');
    }
}
