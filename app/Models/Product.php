<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_code',
        'name',
        'description',
        'category_id',
        'is_active',
        'has_st',
        'is_custom_manufacturing',
        'has_stock_control',
        'unit',
        'profit_margin',
        'min_sale_price',
        'origin_sale_price',
        'sale_price_value',
        'external_reference_codes',
        'item_type',
        'manufacturer_code',
        'gross_weight',
        'net_weight',
        'barcode',
        'created_by',
        'updated_by',
        'company_id',
    ];

    protected $casts = [
        'is_active'                 => 'boolean',
        'has_st'                    => 'boolean',
        'is_custom_manufacturing'   => 'boolean',
        'has_stock_control'         => 'boolean',
        'unit'                      => Unit::class,
        'origin_sale_price'         => OriginSalePrice::class,
        'profit_margin'             => 'decimal:2',
        'min_sale_price'            => MoneyCast::class,
        'sale_price_value'          => MoneyCast::class,
        'external_reference_codes'  => 'array',
        'gross_weight'              => 'decimal:3',
        'net_weight'                => 'decimal:3',
    ];

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tax(): HasOne
    {
        return $this->hasOne(ProductTax::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class);
    }

    public function alternativeUnitConversions(): HasMany
    {
        return $this->hasMany(ProductAlternativeUnit::class);
    }

    public function sefazItemMappings(): HasMany
    {
        return $this->hasMany(SefazItemMapping::class);
    }
}
