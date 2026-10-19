<?php

namespace App\Enum\Commitment;

enum BillingStatus: string
{
    case PENDING = 'pending';
    case INVOICED = 'invoiced';
    case CONFIRMED = 'confirmed';
    case EMISSION_QUEUED = 'emission_queued';
    case FAILED = 'failed';
}
