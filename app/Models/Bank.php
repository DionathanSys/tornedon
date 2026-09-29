<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(BankAccountConnection::class);
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->code} - {$this->name}", ' -'),
        );
    }
}
