<?php

namespace App\Models;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\ProcessingStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitmentImport extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'type', 'status', 'created_by', 'summary'];

    protected $casts = ['type' => ImportType::class, 'status' => ProcessingStatus::class, 'summary' => 'array'];

    public function files(): HasMany
    {
        return $this->hasMany(CommitmentImportFile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
