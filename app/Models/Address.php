<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Address extends Model
{
    protected $appends = [
        'inside_state',
    ];

    protected $fillable = [
        'company_partner_id',
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

    public function companyPartner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class, 'company_partner_id');
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

    protected function insideState(): Attribute
    {
        $companyAddress = $this->companyPartner->address;

        return Attribute::make(
            get: fn() => Str::upper($this->state) === Str::upper($companyAddress['state'])
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
