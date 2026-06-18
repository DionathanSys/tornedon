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
        'same_as_company_address',
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

    protected function number(): Attribute
    {
        return Attribute::make(
            set: fn(mixed $value): mixed => is_string($value) ? str_replace('-', '', $value) : $value,
        );
    }

    protected function insideState(): Attribute
    {
        return Attribute::make(
            get: function (): ?bool {
                $companyAddress = $this->companyPartner?->address;

                if (! is_array($companyAddress) || blank($this->state) || blank($companyAddress['state'] ?? null)) {
                    return null;
                }

                return Str::upper($this->state) === Str::upper($companyAddress['state']);
            }
        );
    }

    protected function sameAsCompanyAddress(): Attribute
    {
        return Attribute::make(
            get: function (): ?bool {
                $companyAddress = $this->companyPartner?->address;

                if (! is_array($companyAddress)) {
                    return null;
                }

                return $this->normalizeAddressValue($this->street) === $this->normalizeAddressValue($companyAddress['street'] ?? null)
                    && $this->normalizeAddressValue($this->number) === $this->normalizeAddressValue($companyAddress['number'] ?? null)
                    && $this->normalizeAddressValue($this->complement) === $this->normalizeAddressValue($companyAddress['complement'] ?? null)
                    && $this->normalizeAddressValue($this->city) === $this->normalizeAddressValue($companyAddress['city'] ?? null)
                    && $this->normalizeAddressValue($this->state) === $this->normalizeAddressValue($companyAddress['state'] ?? null)
                    && $this->normalizeAddressValue($this->postal_code) === $this->normalizeAddressValue($companyAddress['postal_code'] ?? $companyAddress['zip_code'] ?? null)
                    && $this->normalizeAddressValue($this->city_code) === $this->normalizeAddressValue($companyAddress['city_code'] ?? null);
            }
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

    private function normalizeAddressValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : Str::upper($normalized);
    }

}
