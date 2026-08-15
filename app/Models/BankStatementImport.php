<?php

namespace App\Models;

use App\Enum\Financial\BankStatementImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankStatementImport extends Model
{
    protected $fillable = [
        'company_id',
        'financial_account_id',
        'source',
        'reference',
        'file_name',
        'status',
        'imported_at',
        'line_count',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'status' => BankStatementImportStatus::class,
        'imported_at' => 'datetime',
        'line_count' => 'integer',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BankStatementImportRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(BankStatementImportRun::class)->latestOfMany();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
