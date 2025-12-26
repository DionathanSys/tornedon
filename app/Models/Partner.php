<?php

namespace App\Models;

use App\Enum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'document_type',
        'document_number',
        'is_active',
        'state_tax_id',
        'state_tax_indicator',
        'municipal_tax_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type'      => 'array',
        'is_active' => 'boolean',
        'state_tax_indicator' => Enum\Tax\StateTaxIndicator::class,
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function address(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_partner', 'partner_id', 'company_id');
    }
}
