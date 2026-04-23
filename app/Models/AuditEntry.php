<?php

namespace App\Models;

use App\Enum\Audit\AuditSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class AuditEntry extends Model
{
    protected $fillable = [
        'company_id',
        'auditable_type',
        'auditable_id',
        'actor_user_id',
        'actor_name',
        'source',
        'event',
        'action',
        'summary',
        'before',
        'after',
        'diff',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'source' => AuditSource::class,
        'before' => 'array',
        'after' => 'array',
        'diff' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getEntityLabelAttribute(): string
    {
        return self::resolveAuditableTypeLabel($this->auditable_type);
    }

    public function getActionLabelAttribute(): string
    {
        return Str::headline(str_replace('_', ' ', $this->action));
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->source?->description() ?? Str::headline((string) $this->source);
    }

    public static function resolveAuditableTypeLabel(?string $type): string
    {
        return match ($type) {
            'service_order', ServiceOrder::class => 'Ordem de Serviço',
            'requisition', Requisition::class => 'Requisição',
            'production_order', ProductionOrder::class => 'Ordem de Produção',
            Invoice::class => 'Fatura',
            FiscalDocument::class => 'Documento Fiscal',
            SefazDistributionDocument::class => 'DF-e Detectado',
            CashMovement::class => 'Movimento Financeiro',
            BankStatementImport::class => 'Importação de Extrato',
            AccountPayable::class => 'Conta a Pagar',
            AccountReceivable::class => 'Conta a Receber',
            default => Str::headline(class_basename((string) $type)),
        };
    }
}
