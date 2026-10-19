<?php

namespace App\Enum\Commitment;

enum ImportType: string
{
    case COMMITMENTS = 'commitments';
    case PAYMENTS = 'payments';
}
