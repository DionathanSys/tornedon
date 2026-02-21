<?php

namespace App\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\States\CancelledState;
use App\Services\ServiceOrder\States\ClosedState;
use App\Services\ServiceOrder\States\InvoicedState;
use App\Services\ServiceOrder\States\OpenState;
use App\Services\ServiceOrder\States\ServiceOrderState;

class StateResolver
{
    public static function resolve(ServiceOrder $order): ServiceOrderState
    {
        return match ($order->status) {
            State::OPEN      => new OpenState(),
            State::CLOSED    => new ClosedState(),
            State::INVOICED  => new InvoicedState(),
            State::CANCELLED => new CancelledState(),
            default          => new OpenState(),
        };
    }
}
