<?php

namespace App\Models;

use App\Enum\Financial\BankStatementImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImportRun extends Model
{
    protected $fillable = [
        'bank_statement_import_id',
        'file_hash',
        'file_name',
        'status',
        'summary',
        'error_message',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'status' => BankStatementImportStatus::class,
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastSeenLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'last_seen_import_run_id');
    }
}
