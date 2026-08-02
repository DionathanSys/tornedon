<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalRule extends Model
{
    protected $fillable = [
        'company_id',
        'fiscal_profile_id',
        'operation_nature',
        'tax_regime',
        'is_interestadual',
        'product_origin',
        'is_custom_manufacturing',
        'has_st',
        'ncm_prefix',
        'recipient_type',
        'is_final_consumer',
        'cfop',
        'cst_icms',
        'csosn',
        'cst_pis',
        'cst_cofins',
        'cst_ipi',
        'cst_ibs_cbs',
        'classificacao_tributaria_ibs_cbs',
        'indicador_doacao_ibs_cbs',
        'aliquota_icms',
        'aliquota_pis',
        'aliquota_cofins',
        'aliquota_ipi',
        'aliquota_ibs_estadual',
        'aliquota_ibs_municipal',
        'aliquota_cbs',
        'priority',
        'is_active',
        'description',
        'valid_from',
        'valid_until',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_interestadual' => 'boolean',
        'is_custom_manufacturing' => 'boolean',
        'has_st' => 'boolean',
        'is_final_consumer' => 'boolean',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'aliquota_icms' => 'decimal:4',
        'aliquota_pis' => 'decimal:4',
        'aliquota_cofins' => 'decimal:4',
        'aliquota_ipi' => 'decimal:4',
        'aliquota_ibs_estadual' => 'decimal:4',
        'aliquota_ibs_municipal' => 'decimal:4',
        'aliquota_cbs' => 'decimal:4',
        'operation_nature' => \App\Enum\FiscalDocument\OperationNature::class,
        'tax_regime' => \App\Enum\Tax\TaxRegime::class,
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
