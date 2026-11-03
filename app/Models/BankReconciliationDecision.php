<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationDecision extends Model
{
    protected $fillable = [
        'company_id', 'financial_account_id', 'import_id', 'line_id', 'evaluation_id', 'user_id', 'group_id',
        'direction', 'bank_fingerprint', 'party_key', 'line_ids', 'bank_snapshot', 'selected_targets',
        'single_target', 'individual_rank', 'bundle_rank', 'confirmed_at', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'line_ids' => 'array', 'bank_snapshot' => 'array', 'selected_targets' => 'array',
        'individual_rank' => 'integer', 'bundle_rank' => 'integer',
        'confirmed_at' => 'datetime', 'reversed_at' => 'datetime',
        'single_target' => 'boolean',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationEvaluation::class);
    }
}
