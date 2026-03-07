<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Invoice\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id',
        'company_id',
        'invoice_number',
        'invoice_date',
        'status',
        'pending',
        'confirmed',
        'canceled',
        'created_by',
        'updated_by',
        'confirmed_by',
        'canceled_by',
        'confirmed_at',
        'canceled_at',
    ];

    protected $casts = [
        'status' => Status::class,
        'invoice_date' => 'date',
        'pending' => 'boolean',
        'confirmed' => 'boolean',
        'canceled' => 'boolean',
        'confirmed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function accountReceivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /* ==============================
     |  Computed Attributes
     |==============================*/

    /**
     * Total geral da fatura: soma dos totais das OS, requisições e OPs vinculadas.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $soTotal  = $this->serviceOrders->sum(fn ($so) => (float) $so->total_amount);
                $reqTotal = $this->requisitions->sum(fn ($req) => (float) $req->total_amount);
                $poTotal  = $this->productionOrders->sum(function ($po) {
                    return $po->items->sum(function ($item) {
                        $unitPrice = (float) ($item->quoteItem?->unit_price ?? 0);
                        $qty = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);

                        return $unitPrice * $qty;
                    });
                });

                return round($soTotal + $reqTotal + $poTotal, 2);
            }
        );
    }

    /**
     * Total de descontos da fatura: soma dos descontos dos itens de OS e requisições vinculadas.
     */
    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $soDiscount  = $this->serviceOrders->sum(fn ($so) => (float) $so->discount_amount);
                $reqDiscount = $this->requisitions->sum(fn ($req) => (float) $req->discount_amount);

                return round($soDiscount + $reqDiscount, 2);
            }
        );
    }
}
