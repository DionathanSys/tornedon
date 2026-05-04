<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardPaymentProfile extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'brand',
        'acquirer',
        'fee_percent',
        'fee_fixed',
        'settlement_days',
        'active',
    ];

    protected $casts = [
        'fee_percent' => 'float',
        'fee_fixed' => MoneyCast::class,
        'settlement_days' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accountReceivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class);
    }
}
