<?php

namespace App\Enum\Commitment;

enum MatchStatus: string
{
    case UNMATCHED = 'unmatched';
    case SUGGESTED = 'suggested';
    case APPROVED = 'approved';
    case IGNORED = 'ignored';
}
