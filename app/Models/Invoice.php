<?php

namespace App\Models;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    private ?array $resolvedAmounts = null;

    protected $appends = [
        'gross_amount',
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
        'financial_category_id',
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
        'financial_category_id' => 'integer',
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

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
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
     * Total bruto da fatura: soma dos brutos das OS e requisições vinculadas.
     */
    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['gross_amount'];
            }
        );
    }

    /**
     * Total geral líquido da fatura: soma dos totais líquidos das OS e requisições vinculadas.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['total_amount'];
            }
        );
    }

    /**
     * Total líquido de serviços da fatura: soma dos totais líquidos das OS vinculadas.
     */
    protected function servicesAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['services_amount'];
            }
        );
    }

    /**
     * Total líquido de produtos da fatura: soma dos totais líquidos das requisições vinculadas.
     */
    protected function productsAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['products_amount'];
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
                return $this->resolveAmounts()['discount_amount'];
            }
        );
    }

    public function netValue(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['net_value'];
            }
        );
    }

    /**
     * Resolve os totais da fatura diretamente no SQL para evitar dupla conversão monetária.
     *
     * @return array{
     *     gross_amount: float,
     *     services_amount: float,
     *     products_amount: float,
     *     discount_amount: float,
     *     total_amount: float,
     *     net_value: float
     * }
     */
    private function resolveAmounts(): array
    {
        if ($this->resolvedAmounts !== null) {
            return $this->resolvedAmounts;
        }

        if ($this->relationLoaded('serviceOrders') || $this->relationLoaded('requisitions')) {
            $serviceOrders = $this->relationLoaded('serviceOrders')
                ? $this->serviceOrders
                : $this->serviceOrders()->with('items')->get();

            $requisitions = $this->relationLoaded('requisitions')
                ? $this->requisitions
                : $this->requisitions()->with('items')->get();

            $serviceOrders->loadMissing('items');
            $requisitions->loadMissing('items');

            $servicesAmount = round((float) $serviceOrders->sum('total_amount'), 2);
            $productsAmount = round((float) $requisitions->sum('total_amount'), 2);
            $grossAmount = round(
                (float) $serviceOrders->sum('gross_amount') + (float) $requisitions->sum('gross_amount'),
                2
            );
            $discountAmount = round(
                (float) $serviceOrders->sum('discount_amount') + (float) $requisitions->sum('discount_amount'),
                2
            );
            $totalAmount = round($servicesAmount + $productsAmount, 2);

            return $this->resolvedAmounts = [
                'gross_amount' => $grossAmount,
                'services_amount' => $servicesAmount,
                'products_amount' => $productsAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'net_value' => $totalAmount,
            ];
        }

        $serviceOrdersByInvoice = DB::table('service_orders')
            ->leftJoin('service_order_items', 'service_order_items.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.invoice_id', $this->getKey())
            ->groupBy('service_orders.id', 'service_orders.travel_value')
            ->selectRaw('
                COALESCE(SUM(service_order_items.gross_amount), 0) + COALESCE(service_orders.travel_value, 0) as gross_amount,
                COALESCE(SUM(service_order_items.discount_amount), 0) as discount_amount,
                COALESCE(SUM(service_order_items.total_amount), 0) + COALESCE(service_orders.travel_value, 0) as total_amount
            ');

        $serviceTotals = DB::query()
            ->fromSub($serviceOrdersByInvoice, 'service_order_totals')
            ->selectRaw('
                COALESCE(SUM(service_order_totals.gross_amount), 0) as gross_amount,
                COALESCE(SUM(service_order_totals.discount_amount), 0) as discount_amount,
                COALESCE(SUM(service_order_totals.total_amount), 0) as total_amount
            ')
            ->first();

        $requisitionsByInvoice = DB::table('requisitions')
            ->leftJoin('requisition_items', 'requisition_items.requisition_id', '=', 'requisitions.id')
            ->where('requisitions.invoice_id', $this->getKey())
            ->groupBy('requisitions.id')
            ->selectRaw('
                COALESCE(SUM(requisition_items.gross_amount), 0) as gross_amount,
                COALESCE(SUM(requisition_items.discount_amount), 0) as discount_amount,
                COALESCE(SUM(requisition_items.total_amount), 0) as total_amount
            ');

        $productTotals = DB::query()
            ->fromSub($requisitionsByInvoice, 'requisition_totals')
            ->selectRaw('
                COALESCE(SUM(requisition_totals.gross_amount), 0) as gross_amount,
                COALESCE(SUM(requisition_totals.discount_amount), 0) as discount_amount,
                COALESCE(SUM(requisition_totals.total_amount), 0) as total_amount
            ')
            ->first();

        $servicesAmount = round(((float) ($serviceTotals->total_amount ?? 0)) / 100, 2);
        $productsAmount = round(((float) ($productTotals->total_amount ?? 0)) / 100, 2);
        $grossAmount = round(
            (((float) ($serviceTotals->gross_amount ?? 0)) / 100) + (((float) ($productTotals->gross_amount ?? 0)) / 100),
            2
        );
        $discountAmount = round(
            (((float) ($serviceTotals->discount_amount ?? 0)) / 100) + (((float) ($productTotals->discount_amount ?? 0)) / 100),
            2
        );
        $totalAmount = round($servicesAmount + $productsAmount, 2);

        return $this->resolvedAmounts = [
            'gross_amount' => $grossAmount,
            'services_amount' => $servicesAmount,
            'products_amount' => $productsAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'net_value' => $totalAmount,
        ];
    }
}
