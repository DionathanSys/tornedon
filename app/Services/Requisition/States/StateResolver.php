<?php

namespace App\Services\Requisition\States;

use App\Enum\Requisition\Status;
use App\Models\Requisition;

class StateResolver
{
    public static function resolve(Requisition $requisition): RequisitionState
    {
        return match ($requisition->status) {
            Status::OPEN      => new OpenState(),
            Status::CLOSED    => new ClosedState(),
            Status::INVOICED  => new InvoicedState(),
            Status::CANCELLED => new CancelledState(),
            default           => new OpenState(),
        };
    }
}
