<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\CashMovementDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashMovement extends Model
{
    protected $fillable = [
        'company_id',
        'financial_account_id',
        'financial_category_id',
        'direction',
        'transaction_date',
        'amount',
        'description',
        'origin_type',
        'origin_id',
        'counterparty_partner_id',
        'counterparty_financial_account_id',
        'transfer_group_id',
        'notes',
        'participants_snapshot',
        'reversed_at',
        'reversal_of_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'direction' => CashMovementDirection::class,
        'transaction_date' => 'date',
        'amount' => MoneyCast::class,
        'participants_snapshot' => 'array',
        'reversed_at' => 'datetime',
    ];

    protected $appends = [
        'signed_amount',
        'origin_label',
        'party_from_label',
        'party_to_label',
        'account_from_label',
        'account_to_label',
        'tracking_label',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function counterpartyPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'counterparty_partner_id');
    }

    public function counterpartyFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'counterparty_financial_account_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeUnreversed(Builder $query): Builder
    {
        return $query->whereNull('reversal_of_id');
    }

    public function isTransfer(): bool
    {
        if ($this->origin_type !== 'manual' || blank($this->transfer_group_id)) {
            return false;
        }

        $group = $this->transferGroupMovements();

        if ($group->count() !== 2 || ! $group->contains('id', $this->id)) {
            return false;
        }

        $directions = $group
            ->pluck('direction')
            ->map(fn (CashMovementDirection|string|null $direction) => $direction instanceof CashMovementDirection ? $direction->value : $direction)
            ->sort()
            ->values()
            ->all();

        return $directions === [
            CashMovementDirection::INFLOW->value,
            CashMovementDirection::OUTFLOW->value,
        ] && $group->pluck('financial_account_id')->unique()->count() === 2;
    }

    public function transferCounterpart(): ?self
    {
        if (! $this->isTransfer()) {
            return null;
        }

        return $this->transferGroupMovements()
            ->firstWhere('id', '!=', $this->id);
    }

    /**
     * @return Collection<int, self>
     */
    public function transferGroupMovements(): Collection
    {
        if (blank($this->transfer_group_id)) {
            return new Collection();
        }

        return self::query()
            ->where('transfer_group_id', $this->transfer_group_id)
            ->where('origin_type', 'manual')
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->get();
    }

    public function getSignedAmountAttribute(): float
    {
        return round(((float) $this->amount) * ($this->direction?->multiplier() ?? 1), 2);
    }

    public function getOriginLabelAttribute(): string
    {
        return match ($this->origin_type) {
            null, 'manual' => 'Manual',
            AccountPayableInstallmentPayment::class => 'Pagamento de conta a pagar',
            AccountReceivableInstallmentPayment::class => 'Recebimento de conta a receber',
            default => Str::headline(class_basename((string) $this->origin_type)),
        };
    }

    public function getPartyFromLabelAttribute(): string
    {
        return match ($this->direction) {
            CashMovementDirection::OUTFLOW => $this->resolveCompanyLabel(),
            CashMovementDirection::INFLOW => $this->resolveCounterpartyPartnerLabel(),
            default => 'Nao informado',
        };
    }

    public function getPartyToLabelAttribute(): string
    {
        return match ($this->direction) {
            CashMovementDirection::OUTFLOW => $this->resolveCounterpartyPartnerLabel(),
            CashMovementDirection::INFLOW => $this->resolveCompanyLabel(),
            default => 'Nao informado',
        };
    }

    public function getAccountFromLabelAttribute(): string
    {
        return match ($this->direction) {
            CashMovementDirection::OUTFLOW => $this->resolvePrimaryAccountLabel(),
            CashMovementDirection::INFLOW => $this->resolveCounterpartyAccountLabel(),
            default => 'Nao informado',
        };
    }

    public function getAccountToLabelAttribute(): string
    {
        return match ($this->direction) {
            CashMovementDirection::OUTFLOW => $this->resolveCounterpartyAccountLabel(),
            CashMovementDirection::INFLOW => $this->resolvePrimaryAccountLabel(),
            default => 'Nao informado',
        };
    }

    public function getTrackingLabelAttribute(): string
    {
        $segments = [];

        if ($this->counterparty_partner_id !== null || $this->snapshotValue('counterparty_partner_name') !== null) {
            $segments[] = "{$this->party_from_label} -> {$this->party_to_label}";
        }

        if (
            $this->counterparty_financial_account_id !== null
            || $this->snapshotValue('counterparty_financial_account_name') !== null
        ) {
            $segments[] = "{$this->account_from_label} -> {$this->account_to_label}";
        }

        if ($segments !== []) {
            return implode(' | ', $segments);
        }

        return "{$this->resolvePrimaryAccountLabel()} | {$this->origin_label}";
    }

    private function resolveCompanyLabel(): string
    {
        return $this->snapshotValue('company_name')
            ?? $this->company?->name
            ?? 'Empresa';
    }

    private function resolveCounterpartyPartnerLabel(): string
    {
        return $this->snapshotValue('counterparty_partner_name')
            ?? $this->counterpartyPartner?->name
            ?? 'Nao informado';
    }

    private function resolvePrimaryAccountLabel(): string
    {
        return $this->snapshotValue('financial_account_name')
            ?? $this->financialAccount?->display_name
            ?? $this->financialAccount?->name
            ?? 'Nao informado';
    }

    private function resolveCounterpartyAccountLabel(): string
    {
        return $this->snapshotValue('counterparty_financial_account_name')
            ?? $this->counterpartyFinancialAccount?->display_name
            ?? $this->counterpartyFinancialAccount?->name
            ?? 'Nao informado';
    }

    private function snapshotValue(string $key): mixed
    {
        return data_get($this->participants_snapshot, $key);
    }

    public function amount(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->direction)
                ? $this->amount * $this->direction->multiplier()
                : $this->amount,
        );
    }
}
