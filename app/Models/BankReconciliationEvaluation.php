<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliationEvaluation extends Model
{
    protected $fillable = [
        'token', 'company_id', 'import_id', 'user_id', 'context_hash', 'engine_version', 'line_ids',
        'bank_snapshot', 'recommendations', 'complete', 'has_suggestions', 'duration_ms', 'rejected_at',
    ];

    protected $casts = [
        'line_ids' => 'array', 'bank_snapshot' => 'array', 'recommendations' => 'array',
        'complete' => 'boolean', 'has_suggestions' => 'boolean', 'rejected_at' => 'datetime',
    ];
}
