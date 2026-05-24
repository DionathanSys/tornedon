<?php

namespace App\Models;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Concerns\HasAttachments;
use App\Services\ServiceOrder\StateResolver;
use App\Services\ServiceOrder\States\ServiceOrderState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceOrder extends Model
{
    use HasAttachments;

    private ?array $resolvedItemsAmounts = null;

    private ?array $resolvedRequisitionAmounts = null;

    protected static function booted(): void
    {
        static::deleting(function (self $serviceOrder): void {
            if ($serviceOrder->invoice_id) {
                throw new \RuntimeException('Não é possível excluir ordem de serviço que está vinculada a uma fatura.');
            }

            if ($serviceOrder->requisition()->exists()) {
                throw new \RuntimeException('Não é possível excluir ordem de serviço que possui requisição vinculada.');
            }

            $serviceOrder->attachments()->get()->each->delete();
        });
    }

    protected $fillable = [
        'number',
        'customer_id',
        'company_id',
        'quote_id',
        'order_date',
        'scheduled_date',
        'limit_date',
        'completion_date',
        'status',
        'priority',
        'type',
        'solution',
        'equipment_id',
        'location',
        'customer_observations',
        'technician_observations',
        'estimated_hours',
        'actual_hours',
        'value_km',
        'distance_km',
        'travel_value',
        'payment_method',
        'payment_condition',
        'technician_id',
        'supervisor_id',
        'salesperson_id',
        'warranty_expires_at',
        'requires_approval',
        'approved_by_customer',
        'approved_at',
        'customer_signature',
        'customer_signed_at',
        'customer_rating',
        'customer_feedback',
        'invoice_id',
        'items_received',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'scheduled_date' => 'date',
        'limit_date' => 'date',
        'completion_date' => 'date',
        'status' => State::class,
        'priority' => Priority::class,
        'type' => Type::class,
        'payment_method' => PaymentMethod::class,
        'payment_condition' => PaymentCondition::class,
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'value_km' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'travel_value' => 'decimal:2',
        'warranty_expires_at' => 'date',
        'requires_approval' => 'boolean',
        'approved_by_customer' => 'boolean',
        'approved_at' => 'datetime',
        'customer_signed_at' => 'datetime',
        'customer_rating' => 'decimal:1',
        'items_received' => 'string',
        'additional_info' => 'array',
    ];

    protected $appends = [
        'gross_amount',
        'discount_amount',
        'total_amount',
        'requisition_total_amount',
        'services_total_amount',
        'grand_total_amount',
    ];

    public function state(): ServiceOrderState
    {
        return StateResolver::resolve($this);
    }

    /* ==============================
     |  Relacionamentos
     |==============================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function requisition(): HasOne
    {
        return $this->hasOne(Requisition::class);
    }

    public function requisitionItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            RequisitionItem::class,
            Requisition::class,
            'service_order_id',
            'requisition_id',
            'id',
            'id',
        );
    }

    public function remittanceAssets(): BelongsToMany
    {
        return $this->belongsToMany(
            RemittanceAsset::class,
            'service_order_received_assets'
        )
            ->withPivot(['quantity_allocated', 'notes'])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function linkedReturnFiscalDocuments()
    {
        return FiscalDocument::query()
            ->select('fiscal_documents.*')
            ->join('fiscal_document_item_origins', 'fiscal_documents.id', '=', 'fiscal_document_item_origins.return_fiscal_document_id')
            ->join('remittance_assets', function ($join): void {
                $join->on('remittance_assets.fiscal_document_id', '=', 'fiscal_document_item_origins.origin_fiscal_document_id')
                    ->on('remittance_assets.fiscal_document_item_id', '=', 'fiscal_document_item_origins.origin_fiscal_document_item_id');
            })
            ->join('service_order_received_assets', 'service_order_received_assets.remittance_asset_id', '=', 'remittance_assets.id')
            ->where('service_order_received_assets.service_order_id', $this->id)
            ->distinct();
    }

    public function linkedReturnFiscalDocument(): ?FiscalDocument
    {
        return $this->linkedReturnFiscalDocuments()->first();
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('gross_amount', includeTravelValue: true),
        );
    }

    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('discount_amount'),
        );
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('total_amount', includeTravelValue: true),
        );
    }

    protected function requisitionTotalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveRequisitionAmount('total_amount'),
        );
    }

    protected function servicesTotalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('total_amount', includeTravelValue: true),
        );
    }

    protected function grandTotalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->services_total_amount + $this->requisition_total_amount, 2),
        );
    }

    private function resolveItemsAmount(string $column, bool $includeTravelValue = false): float
    {
        $itemsAmount = $this->resolveItemsAmounts()[$column] ?? 0.0;

        if ($includeTravelValue) {
            $itemsAmount += (float) ($this->travel_value ?? 0);
        }

        return round($itemsAmount, 2);
    }

    private function resolveItemsAmounts(): array
    {
        if ($this->resolvedItemsAmounts !== null) {
            return $this->resolvedItemsAmounts;
        }

        if ($this->relationLoaded('items')) {
            return $this->resolvedItemsAmounts = [
                'gross_amount' => round((float) $this->items->sum('gross_amount'), 2),
                'discount_amount' => round((float) $this->items->sum('discount_amount'), 2),
                'total_amount' => round((float) $this->items->sum('total_amount'), 2),
            ];
        }

        $totals = $this->items()
            ->toBase()
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        return $this->resolvedItemsAmounts = [
            'gross_amount' => round(((float) ($totals->gross_amount ?? 0)) / 100, 2),
            'discount_amount' => round(((float) ($totals->discount_amount ?? 0)) / 100, 2),
            'total_amount' => round(((float) ($totals->total_amount ?? 0)) / 100, 2),
        ];
    }

    private function resolveRequisitionAmount(string $column): float
    {
        return round($this->resolveRequisitionAmounts()[$column] ?? 0.0, 2);
    }

    private function resolveRequisitionAmounts(): array
    {
        if ($this->resolvedRequisitionAmounts !== null) {
            return $this->resolvedRequisitionAmounts;
        }

        if ($this->relationLoaded('requisition')) {
            if ($this->requisition === null) {
                return $this->resolvedRequisitionAmounts = [
                    'gross_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'total_amount' => 0.0,
                ];
            }

            return $this->resolvedRequisitionAmounts = [
                'gross_amount' => round((float) $this->requisition->gross_amount, 2),
                'discount_amount' => round((float) $this->requisition->discount_amount, 2),
                'total_amount' => round((float) $this->requisition->total_amount, 2),
            ];
        }

        $totals = RequisitionItem::query()
            ->whereHas('requisition', fn ($query) => $query->where('service_order_id', $this->getKey()))
            ->toBase()
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        return $this->resolvedRequisitionAmounts = [
            'gross_amount' => round(((float) ($totals->gross_amount ?? 0)) / 100, 2),
            'discount_amount' => round(((float) ($totals->discount_amount ?? 0)) / 100, 2),
            'total_amount' => round(((float) ($totals->total_amount ?? 0)) / 100, 2),
        ];
    }
}
