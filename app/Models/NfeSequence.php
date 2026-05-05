<?php

namespace App\Models;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
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
     * @param string $serie Série da NF-e (ex: "1")
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
                    'company_id'       => $companyId,
                    'serie'            => $serie,
                    'operation_nature' => '',
                    'last_number'      => 0,
                ]);
            }

            self::ensureSequenceIsSynchronized($companyId, $serie, (int) $seq->last_number);
            $nextNumber = (int) $seq->last_number + 1;

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
    public static function peekNextNumber(int $companyId, string $serie): int
    {
        $seq = self::where('company_id', $companyId)
            ->where('serie', $serie)
            ->first();

        $currentLastNumber = (int) ($seq->last_number ?? 0);
        self::ensureSequenceIsSynchronized($companyId, $serie, $currentLastNumber);

        return $currentLastNumber + 1;
    }

    /**
     * Confirma o consumo de um número aceito pela API sem reservar previamente a sequência.
     *
     * @return array{number: int, sequence_id: int}
     */
    public static function confirmNumber(int $companyId, string $serie, int $number): array
    {
        $sequence = DB::transaction(function () use ($companyId, $serie, $number) {
            $seq = self::where('company_id', $companyId)
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = self::create([
                    'company_id' => $companyId,
                    'serie' => $serie,
                    'operation_nature' => '',
                    'last_number' => 0,
                ]);
            }

            if ($number > $seq->last_number) {
                $seq->forceFill([
                    'last_number' => $number,
                ])->save();
            }

            return $seq;
        });

        return [
            'number' => $number,
            'sequence_id' => $sequence->id,
        ];
    }

    private static function usedDocumentNumbers(int $companyId, string $serie): Collection
    {
        return FiscalDocument::query()
            ->where('company_id', $companyId)
            ->where('document_series', $serie)
            ->where('document_type', DocumentModel::NFE->value)
            ->where('operation_type', OperationType::SAIDA->value)
            ->whereNotNull('document_number')
            ->pluck('document_number')
            ->map(fn ($number) => (int) preg_replace('/\D/', '', (string) $number))
            ->filter(fn (int $number) => $number > 0)
            ->values();
    }

    private static function ensureSequenceIsSynchronized(int $companyId, string $serie, int $sequenceLastNumber): void
    {
        $usedLastNumber = (int) (self::usedDocumentNumbers($companyId, $serie)->max() ?? 0);

        if ($usedLastNumber !== $sequenceLastNumber) {
            throw new \RuntimeException(sprintf(
                'Divergência na sequência NF-e da série %s: last_number=%d e último documento fiscal=%d. Emissão bloqueada até conciliação.',
                $serie,
                $sequenceLastNumber,
                $usedLastNumber,
            ));
        }
    }
}
