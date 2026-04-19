<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Invoice extends Model
{
    protected $appends = [
        'total_amount',
        'discount_amount',
        'net_value',
        'services_amount',
        'products_amount',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $invoice): void {
            if ($invoice->fiscalDocuments()->exists()) {
                throw new \RuntimeException('Não é possível excluir fatura que já gerou documento fiscal.');
            }
        });
    }

    protected $fillable = [
        'customer_id',
        'company_id',
        'invoice_number',
        'invoice_date',
        'payment_method',
        'payment_condition',
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
        'payment_method' => PaymentMethod::class,
        'payment_condition' => PaymentCondition::class,
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

    public function installments(): HasManyThrough
    {
        return $this->through('accountReceivables')->has('installments');
    }

    public function payments(): HasManyThrough
    {
        return $this->through('accountReceivables')->has('payments');
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
     * Total geral da fatura: soma dos totais das OS, requisições vinculadas.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return round($this->servicesAmount + $this->productsAmount, 2);
            }
        );
    }

    /**
     * Total de serviÃ§os da fatura: soma dos totais das OS vinculadas.
     */
    protected function servicesAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return round(
                    $this->serviceOrders->sum(fn ($serviceOrder) => (float) $serviceOrder->total_amount),
                    2
                );
            }
        );
    }

    /**
     * Total de produtos da fatura: soma dos totais das requisiÃ§Ãµes vinculadas.
     */
    protected function productsAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return round(
                    $this->requisitions->sum(fn ($requisition) => (float) $requisition->total_amount),
                    2
                );
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

    public function netValue(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return round($this->totalAmount - $this->discountAmount, 2);
            }
        );
    }
}
