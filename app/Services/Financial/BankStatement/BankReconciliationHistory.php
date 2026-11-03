<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankReconciliationDecision;
use App\Models\BankStatementLine;

class BankReconciliationHistory
{
    private array $profiles = [];

    public function fingerprint(BankStatementLine $line): ?string
    {
        $name = data_get($line->metadata, 'raw.name');
        $tokens = app(BankStatementMatchText::class)->tokens($name ?: $line->description);
        if (count($tokens) < 2 && (! $name || count($tokens) !== 1 || strlen($tokens[0]) < 5)) {
            return null;
        }
        sort($tokens);

        return hash('sha256', json_encode([data_get($line->import?->metadata, 'bank_id'), $line->direction()?->value, $tokens]));
    }

    public function bonus(BankStatementLine $line, ?string $partyKey): int
    {
        if (! config('bank_reconciliation.history.enabled', true) || ! $line->exists || ! $partyKey || ! ($fingerprint = $this->fingerprint($line))) {
            return 0;
        }
        $key = $line->company_id.':'.$line->financial_account_id.':'.$fingerprint;
        if (! array_key_exists($key, $this->profiles)) {
            $this->profiles[$key] = BankReconciliationDecision::query()
                ->where('company_id', $line->company_id)->where('financial_account_id', $line->financial_account_id)
                ->where('direction', $line->direction()?->value)->where('bank_fingerprint', $fingerprint)
                ->whereNotNull('user_id')->whereNotNull('line_id')->whereNotNull('party_key')->whereNull('reversed_at')
                ->where('confirmed_at', '>=', now()->subDays(max(1, (int) config('bank_reconciliation.history.lookback_days', 180))))
                ->selectRaw('party_key, COUNT(DISTINCT line_id) AS confirmations')->groupBy('party_key')->pluck('confirmations', 'party_key')->all();
        }
        $profile = $this->profiles[$key];
        if (count($profile) !== 1 || (int) ($profile[$partyKey] ?? 0) < max(3, (int) config('bank_reconciliation.history.minimum_confirmations', 3))) {
            return 0;
        }

        return min(15, max(0, (int) config('bank_reconciliation.history.bonus_points', 10)));
    }

    public function clear(): void
    {
        $this->profiles = [];
    }
}
