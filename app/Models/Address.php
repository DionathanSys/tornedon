<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function fullAddress(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->formatFullAddress()
        );
    }

    private function formatFullAddress(): string
    {
        $parts = [];

        if ($this->street) {
            $parts[] = $this->street;
        }

        if ($this->number) {
            $parts[] = $this->number;
        }

        if ($this->complement) {
            $parts[] = $this->complement;
        }

        if ($this->neighborhood) {
            $parts[] = $this->neighborhood;
        }

        if ($this->city) {
            $parts[] = $this->city;
        }

        if ($this->state) {
            $parts[] = $this->state;
        }

        if ($this->postal_code) {
            $parts[] = $this->postal_code;
        }

        if ($this->country) {
            $parts[] = $this->country;
        }

        return implode(', ', $parts);
    }
}
