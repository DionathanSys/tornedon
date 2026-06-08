<?php

namespace App\Models;

use App\Enum\FiscalDocument\DocumentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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
                    'company_id' => $companyId,
                    'serie' => $serie,
                    'last_number' => 0,
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
            'number' => $sequence->last_number,
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

    public static function releaseLastNumberIfAvailable(int $companyId, string $serie, int $number, ?int $documentId = null): bool
    {
        return DB::transaction(function () use ($companyId, $serie, $number, $documentId) {
            $seq = self::where('company_id', $companyId)
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if (! $seq || (int) $seq->last_number !== $number) {
                return false;
            }

            $highestOtherNumber = FiscalDocument::query()
                ->where('company_id', $companyId)
                ->where('rps_series', $serie)
                ->where('document_type', DocumentModel::NFSE->value)
                ->whereNotNull('rps_number')
                ->when($documentId !== null, fn ($query) => $query->whereKeyNot($documentId))
                ->pluck('rps_number')
                ->map(fn ($usedNumber) => (int) preg_replace('/\D/', '', (string) $usedNumber))
                ->filter(fn (int $usedNumber) => $usedNumber > 0)
                ->max();

            if ((int) ($highestOtherNumber ?? 0) >= $number) {
                return false;
            }

            $seq->forceFill([
                'last_number' => max(0, $number - 1),
            ])->save();

            return true;
        });
    }

    public static function isCurrentLastNumber(int $companyId, string $serie, int $number): bool
    {
        $seq = self::query()
            ->where('company_id', $companyId)
            ->where('serie', $serie)
            ->first();

        if (! $seq) {
            return false;
        }

        return (int) $seq->last_number === $number;
    }

    public static function highestUsedNumber(int $companyId, string $serie): int
    {
        return (int) (self::usedRpsNumbers($companyId, $serie)->max() ?? 0);
    }

    public static function canReuseNumber(FiscalDocument $fiscalDocument): bool
    {
        $number = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));

        if ($number < 1 || $serie === '') {
            return false;
        }

        return self::highestUsedNumber((int) $fiscalDocument->company_id, $serie) === $number
            && self::isCurrentLastNumber((int) $fiscalDocument->company_id, $serie, $number);
    }

    public static function synchronizeReservedNumberIfSafe(FiscalDocument $fiscalDocument): bool
    {
        $number = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->rps_number ?? ''));
        $serie = trim((string) ($fiscalDocument->rps_series ?? ''));
        $companyId = (int) $fiscalDocument->company_id;

        if ($number < 1 || $serie === '') {
            return false;
        }

        return DB::transaction(function () use ($companyId, $serie, $number) {
            $seq = self::query()
                ->where('company_id', $companyId)
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = self::create([
                    'company_id' => $companyId,
                    'serie' => $serie,
                    'last_number' => 0,
                ]);
            }

            $currentLastNumber = (int) $seq->last_number;

            if ($currentLastNumber >= $number) {
                return $currentLastNumber === $number;
            }

            $highestUsedNumber = self::highestUsedNumber($companyId, $serie);

            if ($highestUsedNumber !== $number) {
                return false;
            }

            $seq->forceFill([
                'last_number' => $number,
            ])->save();

            return true;
        });
    }

    private static function usedRpsNumbers(int $companyId, string $serie): Collection
    {
        return FiscalDocument::query()
            ->where('company_id', $companyId)
            ->where('rps_series', $serie)
            ->where('document_type', DocumentModel::NFSE->value)
            ->whereNotNull('rps_number')
            ->pluck('rps_number')
            ->map(fn ($number) => (int) preg_replace('/\D/', '', (string) $number))
            ->filter(fn (int $number) => $number > 0)
            ->values();
    }

    private static function ensureSequenceIsSynchronized(int $companyId, string $serie, int $sequenceLastNumber): void
    {
        $usedLastNumber = (int) (self::usedRpsNumbers($companyId, $serie)->max() ?? 0);

        if ($usedLastNumber !== $sequenceLastNumber) {
            throw new \RuntimeException(sprintf(
                'Divergência na sequência NFS-e da série %s: last_number=%d e último RPS=%d. Emissão bloqueada até conciliação.',
                $serie,
                $sequenceLastNumber,
                $usedLastNumber,
            ));
        }
    }
}
