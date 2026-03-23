<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Tax\IssExigibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_code',
        'name',
        'description',
        'price',
        'min_sale_price',
        'accept_customer_discount',
        'cost',
        'category',
        'is_active',
        'requires_approval',
        'tax_classification',
        'tax_rate',
        'nbs_code',
        'cnae_code',
        'municipal_tax_code',
        'iss_exigibility',
        'ncm_code',
        'cfop_code',
        'origin_code',
        'unit_of_measure',
        'additional_info',
        'created_by',
        'updated_by',
        'company_id',
    ];

    protected $casts = [
        'price'            => MoneyCast::class,
        'min_sale_price'   => MoneyCast::class,
        'accept_customer_discount' => 'boolean',
        'cost'             => MoneyCast::class,
        'tax_rate'         => 'decimal:2',
        'is_active'        => 'boolean',
        'requires_approval'=> 'boolean',
        'additional_info'  => 'array',
        'iss_exigibility'  => IssExigibility::class,
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
}
