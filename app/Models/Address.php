<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Address extends Model
{
    protected $fillable = [
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'country',
        'postal_code',
        'city_code',
        'created_by',
        'updated_by',
    ];

    public function company(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_partner', 'partner_id', 'company_id');
    }

    public function partner(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'company_partner', 'company_id', 'partner_id');
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
