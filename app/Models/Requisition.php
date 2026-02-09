<?php

namespace App\Models;

use App\Enum\Requisition\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'customer_id',
        'company_id',
        'service_order_id',
        'sale_date',
        'status',
        'discount_amount',
        'payment_method',
        'payment_condition',
        'observations',
        'delivery_address',
        'delivery_date',
        'salesperson_id',
        'invoice_id',
        'invoiced_at',
        'equipment_id',
        'stock_consumed',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'sale_date' => 'date',
        'delivery_date' => 'date',
        'invoiced_at' => 'datetime',
        'stock_consumed' => 'boolean',
        'discount_amount' => 'decimal:2',
        'additional_info' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
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
