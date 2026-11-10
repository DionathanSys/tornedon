<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Services\Requisition\RequisitionItemReservationService;

class HandleStockReservationCreated
{
    public function handle(RequisitionItemCreated $event): void
    {
        app(RequisitionItemReservationService::class)->sync($event->item, $event->createdBy);
    }
}
