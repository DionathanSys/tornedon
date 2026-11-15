<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\HasInvoiceDiscount;
use App\Models\Concerns\ProtectsInvoicedItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderItem extends Model
{
    use HasInvoiceDiscount;
    use ProtectsInvoicedItem;

    protected $fillable = [
        'service_order_id',
        'service_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount_percentage',
        'discount_amount',
        'invoice_discount_amount',
        'invoice_discount_share_percentage',
        'observations',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'unit_price' => MoneyCast::class,
        'unit_cost' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'invoice_discount_amount' => MoneyCast::class,
        'invoice_discount_share_percentage' => 'decimal:6',
        'gross_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'additional_info' => 'array',
    ];

    /* ==============================
     |  Relacionamentos
     |==============================*/

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
