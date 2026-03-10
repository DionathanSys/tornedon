<?php

namespace App\Models;

use App\Enum\Tax\FiscalOperationType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalRule extends Model
{
    protected $fillable = [
        'fiscal_profile_id',
        'name',
        'operation_type',
        'priority',
        'conditions',
        'result',
        'valid_from',
        'valid_to',
        'is_enabled',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'operation_type' => FiscalOperationType::class,
        'priority' => 'integer',
        'conditions' => 'array',
        'result' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_enabled' => 'boolean',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ==============================
     |  Scopes
     |==============================*/

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeValidAt(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date);
            });
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority');
    }
}
