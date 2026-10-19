<?php

namespace App\Enum\Commitment;

enum ProcessingStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case NEEDS_REVIEW = 'needs_review';
    case FAILED = 'failed';
}
