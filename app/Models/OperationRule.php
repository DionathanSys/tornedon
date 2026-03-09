<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class OperationRule extends Model
{
    protected $fillable = [
        'company_id',
        'operation_nature',
        'default_cfop',
        'cfop_exceptions',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'cfop_exceptions' => 'array',
        'is_active'       => 'boolean',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ==============================
     |  Helpers
     |==============================*/

    /**
     * Resolve o CFOP para um NCM específico.
     *
     * Percorre as exceções por prefixo de NCM. Se nenhuma bater, retorna o CFOP padrão.
     */
    public function resolveCfopForNcm(?string $ncmCode): string
    {
        foreach ($this->cfop_exceptions ?? [] as $exception) {
            $prefix = $exception['ncm_prefix'] ?? null;

            if ($prefix && $ncmCode && str_starts_with($ncmCode, $prefix)) {
                return $exception['cfop'];
            }
        }

        return $this->default_cfop;
    }

    /**
     * Garante que existe uma regra ativa para a empresa e operação informadas.
     *
     * @throws ValidationException
     */
    public static function ensureExistsFor(int $companyId, string $operationNature): void
    {
        $exists = self::where('company_id', $companyId)
            ->where('operation_nature', $operationNature)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'operation_nature' => "Não existe regra fiscal configurada para a operação \"{$operationNature}\". Configure em Configurações → Regras por Operação.",
            ]);
        }
    }
}
