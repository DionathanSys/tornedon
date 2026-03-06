<?php

namespace App\Services\ProductionOrder;

use App\Domain\Exceptions\ProductionOrder\InvalidStateTransitionException;
use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\ProductionOrder\States\CancelledState;
use App\Services\ProductionOrder\States\CompletedState;
use App\Services\ProductionOrder\States\InProgressState;
use App\Services\ProductionOrder\States\InvoicedState;
use App\Services\ProductionOrder\States\ProductionOrderState;
use App\Services\ProductionOrder\States\QcCheckState;
use App\Services\ProductionOrder\States\QueuedState;

class StateResolver
{
    public static function resolve(ProductionOrder $productionOrder): ProductionOrderState
    {
        return match ($productionOrder->status) {
            Status::QUEUED => new QueuedState($productionOrder),
            Status::IN_PROGRESS => new InProgressState($productionOrder),
            Status::QC_CHECK => new QcCheckState($productionOrder),
            Status::COMPLETED  => new CompletedState($productionOrder),
            Status::INVOICED   => new InvoicedState($productionOrder),
            Status::CANCELLED  => new CancelledState($productionOrder),
            default => throw InvalidStateTransitionException::make('resolver estado', 'desconhecido'),
        };
    }
}
