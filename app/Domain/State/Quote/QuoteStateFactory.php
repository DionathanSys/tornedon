<?php

namespace App\Domain\State\Quote;

use App\Models\Quote;

class QuoteStateFactory
{
    public static function make(Quote $quote): QuoteState
    {
        return match ($quote->state) {
            'draft' => new DraftState($quote),
            'pending_approval' => new PendingApprovalState($quote),
            'approved' => new ApprovedState($quote),
            'rejected' => new RejectedState($quote),
            'converted' => new ConvertedState($quote),
            default => new DraftState($quote),
        };
    }
}
