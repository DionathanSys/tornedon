<?php

namespace App\Enum\Commitment;

enum PaymentLineStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';
    case DIVERGENT = 'divergent';
    case ALREADY_RECEIVED = 'already_received';
    case RECEIVED = 'received';
    case IGNORED = 'ignored';
}
