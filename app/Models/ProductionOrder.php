<?php

namespace App\Models;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Services\ProductionOrder\ProductionOrderNumberGenerator;
use App\Services\ProductionOrder\StateResolver;
use App\Services\ProductionOrder\States\ProductionOrderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductionOrder extends Model
{
    protected $fillable = [
        'production_order_number',
        'company_id',
        'quote_id',
        'customer_id',
        'status',
        'priority',
        'started_at',
        'completed_at',
        'cancelled_at',
        'destination_type',
        'requisition_id',
        'observations',
        'assigned_operator',
        'assigned_machine',
        'total_estimated_hours',
        'total_actual_hours',
        'additional_info',
        'invoice_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'priority' => Priority::class,
        'destination_type' => DestinationType::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_estimated_hours' => 'decimal:2',
        'total_actual_hours' => 'decimal:2',
        'additional_info' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProductionOrder $productionOrder) {
            if (empty($productionOrder->production_order_number)) {
                $productionOrder->production_order_number = ProductionOrderNumberGenerator::generate($productionOrder->company_id);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source', 'source_type', 'source_id');
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Retorna o estado atual da ordem de produção (State Machine)
     */
    public function state(): ProductionOrderState
    {
        return StateResolver::resolve($this);
    }

    public function canStart(): bool
    {
        return $this->status === Status::QUEUED;
    }

    public function canComplete(): bool
    {
        return in_array($this->status, [Status::IN_PROGRESS, Status::QC_CHECK]);
    }

    public function canCancel(): bool
    {
        return !in_array($this->status, [Status::COMPLETED, Status::CANCELLED]);
    }

    public function isInProgress(): bool
    {
        return $this->status === Status::IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === Status::COMPLETED;
    }

    public function getCompletionPercentage(): float
    {
        $totalQuantity = $this->items->sum('quantity');
        if ($totalQuantity == 0) {
            return 0;
        }

        $producedQuantity = $this->items->sum('quantity_produced');
        return ($producedQuantity / $totalQuantity) * 100;
    }

    public function getQualityRate(): float
    {
        $totalProduced = $this->items->sum('quantity_produced');
        if ($totalProduced == 0) {
            return 0;
        }

        $totalApproved = $this->items->sum('quantity_approved');
        return ($totalApproved / $totalProduced) * 100;
    }
}
