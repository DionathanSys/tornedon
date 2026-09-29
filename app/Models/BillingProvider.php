<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingProvider extends Model
{
    protected $fillable = [
        'key',
        'name',
        'adapter_class',
        'is_active',
        'capabilities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capabilities' => 'array',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(BankAccountConnection::class);
    }
}
