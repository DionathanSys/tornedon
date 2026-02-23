<?php

namespace App\Services\Quote\States;

use App\Enum\Quote\Status;
use App\Models\Quote;

class StateResolver
{
    public static function resolve(Quote $quote): QuoteState
    {
        return match ($quote->status) {
            Status::DRAFT    => new DraftState(),
            Status::SENT     => new SentState(),
            Status::APPROVED => new ApprovedState(),
            Status::REJECTED => new RejectedState(),
            Status::EXPIRED  => new ExpiredState(),
            default          => new DraftState(),
        };
    }
}
