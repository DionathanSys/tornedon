<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Services\Requisition\RequisitionItemReservationService;

class HandleStockReservationDeleted
{
    public function handle(RequisitionItemDeleted $event): void
    {
        app(RequisitionItemReservationService::class)->sync($event->item, $event->deletedBy, true);
    }
}
