<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CommitmentBillingBatch extends Model
{
    protected $fillable = ['company_id', 'created_by', 'run_token', 'emit_nfse', 'status', 'started_at', 'finished_at'];

    protected $casts = ['emit_nfse' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            if ($batch->isDirty('company_id')) {
                throw ValidationException::withMessages(['company_id' => 'Não é permitido transferir lotes entre empresas.']);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommitmentBillingBatchItem::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => 'Na fila', 'processing' => 'Processando', 'completed' => 'Concluído',
            'partial_failed' => 'Concluído com falhas', 'failed' => 'Falhou', default => $this->status,
        };
    }
}
