<?php

namespace App\Models;

use App\Enum\Tax\TaxRegime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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
        'iss_rate_default',
        'iss_withheld_default',
        'nfse_special_tax_regime',
        'default_service_code',
        'service_cnae_code',
        'default_nbs_code',
        'default_municipal_tax_code',
        'default_nfse_additional_information',
        'cfop_rules',
        'additional_tax_information_default',
        'additional_taxpayer_information_default',
        'additional_purchase_information_default',
        'taxpayer_observations_default',
        'tax_observations_default',
        'informacoes_complementares_padrao',
        'ruleset_checksum',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tax_regime'                                => TaxRegime::class,
        'is_active'                                 => 'boolean',
        'icms_aliquota_interna'                     => 'float',
        'icms_reducao_base'                         => 'float',
        'icms_st_aliquota'                          => 'float',
        'icms_st_mva'                               => 'float',
        'icms_st_reducao_base'                      => 'float',
        'pis_aliquota_default'                      => 'float',
        'cofins_aliquota_default'                   => 'float',
        'ipi_aliquota_default'                      => 'float',
        'iss_rate_default'                          => 'float',
        'iss_withheld_default'                      => 'boolean',
        'icms_aliquotas_interestaduais'             => 'array',
        'cfop_rules'                                => 'array',
        'additional_purchase_information_default'   => 'array',
        'taxpayer_observations_default'             => 'array',
        'tax_observations_default'                  => 'array',
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

    public function resolveCfopForOperationNature(string $operationNature, ?string $ncmCode, bool $isCustomManufacturing = false): ?string
    {
        Log::debug("Resolvendo CFOP por natureza de operação '{$operationNature}', código NCM '{$ncmCode}' e fabricação customizada: " . ($isCustomManufacturing ? 'sim' : 'não') . " usando perfil fiscal ID {$this->id}");

        $rules = $this->cfop_rules ?? [];
        $rule = $rules[$operationNature] ?? null;

        Log::debug('Regra de CFOP encontrada para natureza de operação?', [
            'rules' => $rules,
            'rule' => $rule
        ]);

        if (! is_array($rule)) {
            return null;
        }

        // 1. Verificar se é fabricação customizada (prioridade máxima)
        if ($isCustomManufacturing && isset($rule['custom_manufacturing_cfop'])) {
            $customCfop = $rule['custom_manufacturing_cfop'];
            if (is_string($customCfop) && $customCfop !== '') {
                Log::debug("CFOP para fabricação customizada encontrado: '{$customCfop}'");
                return $customCfop;
            }
        }

        $defaultCfop = $rule['default_cfop'] ?? null;
        $exceptions = $rule['exceptions'] ?? [];

        Log::debug('Exceções de CFOP encontradas para natureza de operação?', [
            'exceptions' => $exceptions,
            'ncmCode' => $ncmCode
        ]);

        // 2. Verificar exceções por NCM
        if (is_array($exceptions) && ! empty($ncmCode)) {
            foreach ($exceptions as $prefix => $cfop) {
                Log::debug("Verificando exceção de CFOP: prefixo '{$prefix}' para NCM '{$ncmCode}'");
                if (! is_string($prefix) || ! is_string($cfop)) {
                    continue;
                }

                if (str_starts_with($ncmCode, $prefix)) {
                    Log::debug("Exceção de CFOP encontrada: prefixo '{$prefix}' para NCM '{$ncmCode}' => CFOP '{$cfop}'");
                    return $cfop;
                }
            }
        }

        // 3. Usar CFOP padrão
        Log::debug('CFOP resolvido para natureza de operação?', [
            'cfop' => $defaultCfop
        ]);

        return $defaultCfop;
    }
}
