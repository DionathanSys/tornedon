<?php

namespace App\Enum\Commitment;

enum ReceiptStatus: string
{
    case PENDING = 'pending';
    case RECEIVED = 'received';
    case NEEDS_REVIEW = 'needs_review';
}
