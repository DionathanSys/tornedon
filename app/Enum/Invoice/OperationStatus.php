<?php

namespace App\Enum\Invoice;

enum OperationStatus: string
{
    case REQUESTED = 'requested';
    case RUNNING = 'running';
    case AWAITING_EXTERNAL = 'awaiting_external';
    case NEEDS_INTERVENTION = 'needs_intervention';
    case COMPLETED = 'completed';
    case ABORTED = 'aborted';

    public function isActive(): bool
    {
        return ! in_array($this, [self::COMPLETED, self::ABORTED], true);
    }
}
