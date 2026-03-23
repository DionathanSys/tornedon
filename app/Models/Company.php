<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'name',
        'document_number',
        'address',
        'phone',
        'email',
        'certificate',
        'municipal_tax_id',
        'state_tax_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'address' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'service_provision_location',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'company_partner', 'company_id', 'partner_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(CompanyPreference::class);
    }

    public function fiscalProfile(): HasOne
    {
        return $this->hasOne(FiscalProfile::class);
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    public function serviceProvisionLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                $address = $this->address ?? [];
                $city = $address['city'] ?? '';
                $state = $address['state'] ?? '';

                if (!$city && !$state) {
                    return '';
                }

                return trim("{$city} - {$state}", ' -');
            }
        );
    }

    /**
     * Busca uma preferência desta empresa
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        return CompanyPreference::get($key, $this->id, $default);
    }

    /**
     * Define uma preferência desta empresa
     *
     * @param string $key
     * @param mixed $value
     * @return CompanyPreference
     */
    public function setPreference(string $key, mixed $value): CompanyPreference
    {
        return CompanyPreference::set($key, $value, $this->id);
    }

    /**
     * Remove uma preferência desta empresa
     *
     * @param string $key
     * @return bool
     */
    public function removePreference(string $key): bool
    {
        return CompanyPreference::remove($key, $this->id);
    }
}
