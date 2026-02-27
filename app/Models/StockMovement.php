<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\StockMovement\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockMovement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_stock_id',
        'product_id',
        'company_id',
        'type',
        'quantity',
        'unit_price',
        'total_amount',
        'reason',
        'observations',
        'source_type',
        'source_id',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => Type::class,
        'quantity' => 'decimal:3',
        'unit_price' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'additional_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function productStock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
}
