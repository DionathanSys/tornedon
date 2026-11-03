<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankReconciliationDecision;
use App\Models\BankReconciliationEvaluation;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankReconciliationQualityService
{
    public function bankSnapshot(Collection $lines): array
    {
        return $lines->sortBy('id')->map(fn (BankStatementLine $line): array => [
            'id' => $line->id, 'import_id' => $line->bank_statement_import_id,
            'financial_account_id' => $line->financial_account_id, 'direction' => $line->direction()?->value,
            'date' => $line->transaction_date->toDateString(), 'amount_cents' => (int) round((float) $line->amount * 100),
            'description' => $line->description, 'name' => data_get($line->metadata, 'raw.name'),
            'source_hash' => $line->source_payload_hash,
        ])->values()->all();
    }

    public function evaluate(Collection $lines, array $response, int $durationMs, ?int $userId): string
    {
        $snapshot = $this->bankSnapshot($lines);
        $imports = $lines->pluck('bank_statement_import_id')->unique();
        $evaluation = BankReconciliationEvaluation::create([
            'token' => (string) Str::uuid(), 'company_id' => $lines->first()->company_id,
            'import_id' => $imports->count() === 1 ? $imports->first() : null, 'user_id' => $userId,
            'context_hash' => $this->contextHash($snapshot), 'engine_version' => BankStatementLine::SUGGESTIONS_VERSION,
            'line_ids' => array_column($snapshot, 'id'), 'bank_snapshot' => $snapshot,
            'recommendations' => ['records' => $response['records'], 'bundles' => $response['bundles']],
            'complete' => $response['failed_lines'] === 0 && ! $response['advanced_error'],
            'has_suggestions' => $response['records'] !== [] || $response['bundles'] !== [],
            'duration_ms' => max(0, $durationMs),
        ]);

        return $evaluation->token;
    }

    public function reject(int $companyId, Collection $lines, string $token, ?int $userId): void
    {
        $ids = $lines->pluck('id')->unique();
        $currentLines = BankStatementLine::query()->where('company_id', $companyId)->whereIn('id', $ids)->get();
        $evaluation = $currentLines->count() === $ids->count() ? $this->evaluation($companyId, $currentLines, $token, $userId) : null;
        if (! $evaluation || ! $evaluation->has_suggestions) {
            throw ValidationException::withMessages(['suggestions' => 'Atualize as sugestões antes de registrar esta avaliação.']);
        }
        $evaluation->update(['rejected_at' => $evaluation->rejected_at ?? now()]);
    }

    public function target(string $type, CashMovement|AccountPayableInstallment|AccountReceivableInstallment $record, int $amountCents): array
    {
        $party = match (true) {
            $record instanceof CashMovement => $record->counterparty_label,
            $record instanceof AccountPayableInstallment => $record->accountPayable?->counterparty_label,
            default => $record->accountReceivable?->counterparty_label,
        };

        return [
            'type' => $type, 'id' => $record->id, 'amount_cents' => $amountCents,
            'party_key' => app(BankStatementMatchScorer::class)->counterpartyKey($record),
            'description' => $record->description,
            'party' => $party === 'Não informado' ? null : $party,
        ];
    }

    public function confirm(Collection $lines, array $targets, ?int $userId, ?int $groupId = null, ?string $evaluationToken = null): void
    {
        $first = $lines->first();
        $evaluation = $evaluationToken ? $this->evaluation($first->company_id, $lines, $evaluationToken, $userId) : null;
        $keys = $this->targetKeys($targets);
        $records = $evaluation?->recommendations['records'] ?? [];
        $bundles = $evaluation?->recommendations['bundles'] ?? [];
        $individualRank = null;
        $bundleRank = null;
        if (count($targets) === 1) {
            foreach ($records as $index => $record) {
                if ($keys === [$record['type'].':'.$record['id']]) {
                    $individualRank = $index + 1;
                    break;
                }
            }
        }
        foreach ($bundles as $index => $bundle) {
            $bundleTargets = array_map(fn ($item) => ['type' => $bundle['type'], 'id' => $item['id']], $bundle['items']);
            if ($this->targetKeys($bundleTargets) === $keys) {
                $bundleRank = $index + 1;
                break;
            }
        }
        $imports = $lines->pluck('bank_statement_import_id')->unique();
        $parties = collect($targets)->pluck('party_key')->filter()->unique();
        BankReconciliationDecision::create([
            'company_id' => $first->company_id, 'financial_account_id' => $first->financial_account_id,
            'import_id' => $imports->count() === 1 ? $imports->first() : null,
            'line_id' => $lines->count() === 1 ? $first->id : null, 'evaluation_id' => $evaluation?->id,
            'user_id' => $userId, 'group_id' => $groupId, 'direction' => $first->direction()->value,
            'bank_fingerprint' => $lines->count() === 1 ? app(BankReconciliationHistory::class)->fingerprint($first) : null,
            'party_key' => $parties->count() === 1 && $parties->count() === collect($targets)->pluck('party_key')->unique()->count() ? $parties->first() : null,
            'line_ids' => $lines->pluck('id')->sort()->values()->all(), 'bank_snapshot' => $this->bankSnapshot($lines),
            'selected_targets' => $targets, 'individual_rank' => $individualRank, 'bundle_rank' => $bundleRank,
            'single_target' => count($targets) === 1,
            'confirmed_at' => now(),
        ]);
        app(BankReconciliationHistory::class)->clear();
    }

    public function reverse(BankStatementLine $line, string $reason, ?int $groupId = null): void
    {
        $query = BankReconciliationDecision::query()->where('company_id', $line->company_id)->whereNull('reversed_at');
        $groupId ? $query->where('group_id', $groupId) : $query->whereNull('group_id')->where('line_id', $line->id);
        $query->update(['reversed_at' => now(), 'reversal_reason' => $reason, 'updated_at' => now()]);
        app(BankReconciliationHistory::class)->clear();
    }

    private function evaluation(int $companyId, Collection $lines, string $token, ?int $userId): ?BankReconciliationEvaluation
    {
        if (! Str::isUuid($token) || $lines->contains(fn ($line) => (int) $line->company_id !== $companyId)) {
            return null;
        }
        $evaluation = BankReconciliationEvaluation::query()->where('company_id', $companyId)->where('user_id', $userId)
            ->where('token', $token)->where('created_at', '>=', now()->subMinutes(max(1, (int) config('bank_reconciliation.suggestions_ttl_minutes', 1440))))->first();

        return $evaluation && hash_equals($evaluation->context_hash, $this->contextHash($this->bankSnapshot($lines))) ? $evaluation : null;
    }

    private function contextHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function targetKeys(array $targets): array
    {
        $keys = array_map(fn ($target) => $target['type'].':'.$target['id'], $targets);
        sort($keys);

        return $keys;
    }
}
