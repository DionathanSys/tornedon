<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\MatchStatus;
use App\Models\CustomerCommitment;
use App\Services\Audit\AuditRecorder;

class SuggestCommitmentMatchesService
{
    public function refresh(int $companyId, int $commitmentId, int $userId): CustomerCommitment
    {
        return app(CommitmentReviewContext::class)->transaction($companyId, $commitmentId, $userId, function (CustomerCommitment $record) use ($userId) {
            if ($record->billing()->exists() || in_array($record->status, [MatchStatus::APPROVED, MatchStatus::IGNORED], true)) {
                return $record;
            }
            $result = app(CommitmentMatchEngine::class)->analyze($record);
            $record->update([
                'suggested_service_order_id' => $result['eligible_ids'][0] ?? null,
                'suggestion_count' => count($result['eligible_ids']),
                'status' => $result['eligible_ids'] === [] ? MatchStatus::UNMATCHED : MatchStatus::SUGGESTED,
                'match_diagnostics' => $result, 'matched_at' => now(),
            ]);
            app(AuditRecorder::class)->recordModelEvent($record, 'commitment.matches_refreshed', 'Sugestões de OS atualizadas.', actorUserId: $userId, metadata: ['suggestion_count' => $record->suggestion_count]);

            return $record;
        });
    }
}
