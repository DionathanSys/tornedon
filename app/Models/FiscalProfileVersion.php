<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalProfileVersion extends Model
{
    protected $fillable = [
        'fiscal_profile_id',
        'version',
        'valid_from',
        'valid_to',
        'status',

        // ICMS
        'icms_cst_default',
        'icms_csosn_default',
        'icms_aliquota_interna',
        'icms_reducao_base',
        'icms_modalidade_base_calculo',

        // ICMS ST
        'icms_st_aliquota',
        'icms_st_mva',
        'icms_st_reducao_base',

        // Interestaduais
        'icms_aliquotas_interestaduais',

        // PIS
        'pis_cst_default',
        'pis_aliquota_default',

        // COFINS
        'cofins_cst_default',
        'cofins_aliquota_default',

        // IPI
        'ipi_cst_default',
        'ipi_aliquota_default',
        'ipi_enquadramento',

        // CFOP
        'cfop_rules',

        // Info complementar
        'informacoes_complementares_padrao',

        // Controle
        'ruleset_checksum',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'icms_aliquota_interna' => 'float',
        'icms_reducao_base' => 'float',
        'icms_st_aliquota' => 'float',
        'icms_st_mva' => 'float',
        'icms_st_reducao_base' => 'float',
        'pis_aliquota_default' => 'float',
        'cofins_aliquota_default' => 'float',
        'ipi_aliquota_default' => 'float',
        'icms_aliquotas_interestaduais' => 'array',
        'cfop_rules' => 'array',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FiscalRule::class);
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
     |  Scopes
     |==============================*/

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeValidAt(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date);
            });
    }

    /* ==============================
     |  Helpers
     |==============================*/

    public function isValidAt(Carbon $date): bool
    {
        return $this->valid_from <= $date
            && ($this->valid_to === null || $this->valid_to >= $date);
    }

    /**
     * Retorna a alíquota interestadual para uma UF de destino.
     */
    public function getAliquotaInterestadual(string $uf): ?float
    {
        $aliquotas = $this->icms_aliquotas_interestaduais ?? [];

        return isset($aliquotas[$uf]) ? (float) $aliquotas[$uf] : null;
    }

    /**
     * Retorna o CFOP mapeado para uma natureza de operação.
     */
    public function getCfopForNature(string $operationNature): ?string
    {
        $rules = $this->cfop_rules ?? [];

        return $rules[$operationNature] ?? null;
    }
}
