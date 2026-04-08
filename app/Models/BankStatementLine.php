<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\BankStatementLineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_statement_import_id',
        'company_id',
        'financial_account_id',
        'cash_movement_id',
        'transaction_date',
        'amount',
        'balance_amount',
        'description',
        'external_id',
        'document_number',
        'reconciliation_status',
        'reconciled_at',
        'metadata',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => MoneyCast::class,
        'balance_amount' => MoneyCast::class,
        'reconciliation_status' => BankStatementLineStatus::class,
        'reconciled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
