<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    
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
