<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliationGroup extends Model
{
    public const RETAINED_RECEIPT = 'retained_receipt';

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationGroupItem::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}
