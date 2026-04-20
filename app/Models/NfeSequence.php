<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NfeSequence extends Model
{
    protected $fillable = [
        'company_id',
        'serie',
        'operation_nature',
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
     * Reserva e retorna o próximo número da NF-e de forma atômica.
     *
     * Usa SELECT ... FOR UPDATE para evitar concorrência (dois documentos
     * com o mesmo número). O registro é criado automaticamente se não existir.
     *
     * @param int    $companyId
     * @param string $serie           Série da NF-e (ex: "1")
     * @param string $operationNature Natureza da operação (ex: "VENDA DENTRO DO ESTADO")
     * @return array{number: int, sequence_id: int}
     */
    public static function nextNumber(int $companyId, string $serie, string $operationNature): array
    {
        $sequence = DB::transaction(function () use ($companyId, $serie, $operationNature) {
            $seq = self::where('company_id', $companyId)
                ->where('serie', $serie)
                ->where('operation_nature', $operationNature)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = self::create([
                    'company_id'       => $companyId,
                    'serie'            => $serie,
                    'operation_nature' => $operationNature,
                    'last_number'      => 0,
                ]);
            }

            $usedNumbers = self::usedDocumentNumbers($companyId, $serie);
            $nextNumber = max($seq->last_number, $usedNumbers->max() ?? 0) + 1;

            while ($usedNumbers->contains($nextNumber)) {
                $nextNumber++;
            }

            $seq->forceFill([
                'last_number' => $nextNumber,
            ])->save();

            return $seq;
        });

        return [
            'number'      => $sequence->last_number,
            'sequence_id' => $sequence->id,
        ];
    }

    /**
     * Retorna o próximo número que SERIA reservado, sem incrementar.
     *
     * Útil para preview: mostra ao usuário o número correto sem consumir a sequência.
     */
    public static function peekNextNumber(int $companyId, string $serie, string $operationNature): int
    {
        $seq = self::where('company_id', $companyId)
            ->where('serie', $serie)
            ->where('operation_nature', $operationNature)
            ->first();

        $usedNumbers = self::usedDocumentNumbers($companyId, $serie);
        $nextNumber = max($seq->last_number ?? 0, $usedNumbers->max() ?? 0) + 1;

        while ($usedNumbers->contains($nextNumber)) {
            $nextNumber++;
        }

        return $nextNumber;
    }

    private static function usedDocumentNumbers(int $companyId, string $serie): Collection
    {
        return FiscalDocument::query()
            ->where('company_id', $companyId)
            ->where('document_series', $serie)
            ->whereNotNull('document_number')
            ->pluck('document_number')
            ->map(fn ($number) => (int) preg_replace('/\D/', '', (string) $number))
            ->filter(fn (int $number) => $number > 0)
            ->values();
    }
}
