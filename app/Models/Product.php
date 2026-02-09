<?php

namespace App\Models;

use App\Enum\Product\Unit;
use App\Services\Product\ProductCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'is_custom_manufacturing',
        'unit',
        'alternative_units',
        'profit_margin',
        'min_sale_price',
        'created_by',
        'updated_by',
        'company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_custom_manufacturing' => 'boolean',
        'unit' => Unit::class,
        'alternative_units' => 'array',
        'profit_margin' => 'decimal:2',
        'min_sale_price' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Product $product) {
            if (empty($product->product_code)) {
                $product->product_code = ProductCodeService::generate($product->company_id);
            }
        });
    }

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
}
