<?php

namespace App\Models;

use App\Enum\Tax\TaxRegime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalProfile extends Model
{
    protected $fillable = [
        'company_id',
        'tax_regime',
        'cnae_principal',
        'icms_cst_default',
        'icms_csosn_default',
        'icms_aliquota_interna',
        'icms_reducao_base',
        'icms_modalidade_base_calculo',
        'icms_st_aliquota',
        'icms_st_mva',
        'icms_st_reducao_base',
        'icms_aliquotas_interestaduais',
        'pis_cst_default',
        'pis_aliquota_default',
        'cofins_cst_default',
        'cofins_aliquota_default',
        'ipi_cst_default',
        'ipi_aliquota_default',
        'ipi_enquadramento',
        'cfop_rules',
        'informacoes_adicionais_fisco',
        'informacoes_adicionais_contribuinte',
        'informacoes_adicionais_compra',
        'observacoes_contribuinte',
        'observacoes_fisco',
        'informacoes_complementares_padrao',
        'ruleset_checksum',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tax_regime' => TaxRegime::class,
        'is_active' => 'boolean',
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
        'informacoes_adicionais_compra' => 'array',
        'observacoes_contribuinte' => 'array',
        'observacoes_fisco' => 'array',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function getAliquotaInterestadual(string $uf): ?float
    {
        $aliquotas = $this->icms_aliquotas_interestaduais ?? [];

        return isset($aliquotas[$uf]) ? (float) $aliquotas[$uf] : null;
    }

    public function getCfopForNature(string $operationNature): ?string
    {
        $rules = $this->cfop_rules ?? [];

        $rule = $rules[$operationNature] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        return $rule['default_cfop'] ?? null;
    }

    public function resolveCfopForOperationNature(string $operationNature, ?string $ncmCode): ?string
    {
        $rules = $this->cfop_rules ?? [];
        $rule = $rules[$operationNature] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        $defaultCfop = $rule['default_cfop'] ?? null;
        $exceptions = $rule['exceptions'] ?? [];

        if (is_array($exceptions) && ! empty($ncmCode)) {
            foreach ($exceptions as $prefix => $cfop) {
                if (! is_string($prefix) || ! is_string($cfop)) {
                    continue;
                }

                if (str_starts_with($ncmCode, $prefix)) {
                    return $cfop;
                }
            }
        }

        return $defaultCfop;
    }
}
