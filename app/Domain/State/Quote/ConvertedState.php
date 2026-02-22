<?php

namespace App\Domain\State\Quote;

class ConvertedState extends QuoteState
{
    public function getName(): string
    {
        return 'converted';
    }
}
