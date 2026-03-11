<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class NfseSequence extends Model
{
    protected $fillable = [
        'company_id',
        'serie',
        'last_number',
    ];

    protected $casts = [
        'last_number' => 'integer',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /* ==============================
     |  Business Logic
     |==============================*/

    /**
     * Reserva e retorna o próximo número do RPS de forma atômica.
     *
     * Usa SELECT ... FOR UPDATE para evitar concorrência.
     * O registro é criado automaticamente se não existir.
     *
     * @return array{number: int, sequence_id: int}
     */
    public static function nextNumber(int $companyId, string $serie): array
    {
        $sequence = DB::transaction(function () use ($companyId, $serie) {
            $seq = self::where('company_id', $companyId)
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = self::create([
                    'company_id'  => $companyId,
                    'serie'       => $serie,
                    'last_number' => 0,
                ]);
            }

            $seq->increment('last_number');
            $seq->refresh();

            return $seq;
        });

        return [
            'number'      => $sequence->last_number,
            'sequence_id' => $sequence->id,
        ];
    }

    /**
     * Retorna o próximo número que SERIA reservado, sem incrementar.
     */
    public static function peekNextNumber(int $companyId, string $serie): int
    {
        $seq = self::where('company_id', $companyId)
            ->where('serie', $serie)
            ->first();

        return ($seq->last_number ?? 0) + 1;
    }
}
