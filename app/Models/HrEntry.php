<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class HrEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['competence_date' => 'date', 'due_date' => 'date', 'confirmed_at' => 'datetime', 'items' => 'array', 'advance_deductions' => 'array', 'snapshot' => 'array', 'gross_amount' => 'decimal:2', 'deduction_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if (($entry->status ?? 'draft') !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Crie um rascunho e confirme pelo serviço de RH.']);
            }
        });
        static::updating(function (self $entry): void {
            if ($entry->isDirty('status') && ($entry->getRawOriginal('status') === 'cancelled' || ($entry->getRawOriginal('status') === 'confirmed' && $entry->status !== 'cancelled'))) {
                throw ValidationException::withMessages(['status' => 'Não é permitido reabrir lançamentos confirmados ou cancelados.']);
            }
            if ($entry->getRawOriginal('status') !== 'draft' && array_diff(array_keys($entry->getDirty()), ['status', 'updated_at'])) {
                throw ValidationException::withMessages(['entry' => 'Lançamentos confirmados são imutáveis. Cancele e crie outro lançamento.']);
            }
        });
        static::deleting(function (self $entry): void {
            if ($entry->status !== 'draft') {
                throw ValidationException::withMessages(['entry' => 'Somente rascunhos podem ser excluídos.']);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class, 'hr_person_id');
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(HrReceipt::class);
    }
}
