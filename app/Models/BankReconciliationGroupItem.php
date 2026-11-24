<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationGroupItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['created_payment' => 'boolean', 'created_document' => 'boolean', 'returned_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationGroup::class, 'bank_reconciliation_group_id');
    }

    public function receivablePayment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivableInstallmentPayment::class, 'receivable_payment_id');
    }

    public function payablePayment(): BelongsTo
    {
        return $this->belongsTo(AccountPayableInstallmentPayment::class, 'payable_payment_id');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
