<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Services\Requisition\RequisitionItemReservationService;

class HandleStockReservationUpdated
{
    public function handle(RequisitionItemUpdated $event): void
    {
        app(RequisitionItemReservationService::class)->sync($event->item, $event->updatedBy);
    }
}
