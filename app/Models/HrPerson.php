<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPerson extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['roles' => 'array', 'base_amount' => 'decimal:2', 'started_at' => 'date', 'ended_at' => 'date', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyPartner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HrEntry::class);
    }
}
