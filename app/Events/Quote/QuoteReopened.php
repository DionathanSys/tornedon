<?php

namespace App\Events\Quote;

use App\Models\Quote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuoteReopened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        public readonly int $reopenedBy,
    ) {}
}
