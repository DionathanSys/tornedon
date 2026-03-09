<?php

namespace App\Models;

use App\Enum\Tax\TaxRegime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FiscalProfile extends Model
{
    protected $fillable = [
        'company_id',
        'tax_regime',
        'cnae_principal',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tax_regime' => TaxRegime::class,
        'is_active' => 'boolean',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FiscalProfileVersion::class);
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(FiscalProfileVersion::class)
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', now());
            })
            ->latest('version');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ==============================
     |  Helpers
     |==============================*/

    public function getActiveVersion(): ?FiscalProfileVersion
    {
        return $this->activeVersion;
    }

    /**
     * Retorna o próximo número de versão disponível.
     */
    public function nextVersionNumber(): int
    {
        return ($this->versions()->max('version') ?? 0) + 1;
    }
}
