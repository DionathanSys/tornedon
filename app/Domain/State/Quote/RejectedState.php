<?php

namespace App\Domain\State\Quote;

class RejectedState extends QuoteState
{
    public function getName(): string
    {
        return 'rejected';
    }
}
