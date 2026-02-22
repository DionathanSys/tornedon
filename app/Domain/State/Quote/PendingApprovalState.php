<?php

namespace App\Domain\State\Quote;

class PendingApprovalState extends QuoteState
{
    public function getName(): string
    {
        return 'pending_approval';
    }

    public function canApprove(): bool { return true; }
    public function canReject(): bool { return true; }

    public function approve(int $userId): bool
    {
        $this->quote->state = 'approved';
        $this->quote->approved_by = $userId;
        $this->quote->approved_at = now();
        $this->quote->save();
        return true;
    }

    public function reject(int $userId, string $reason): bool
    {
        $this->quote->state = 'rejected';
        $this->quote->rejected_by = $userId;
        $this->quote->rejected_at = now();
        $this->quote->rejection_reason = $reason;
        $this->quote->save();
        return true;
    }
}
