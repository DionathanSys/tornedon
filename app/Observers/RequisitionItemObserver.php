<?php

namespace App\Observers;

use App\Models\RequisitionItem;
use App\Services\Amounts\CommercialAmountSyncService;

class RequisitionItemObserver
{
    public function saved(RequisitionItem $requisitionItem): void
    {
        app(CommercialAmountSyncService::class)->syncRequisition((int) $requisitionItem->requisition_id);
    }

    public function deleted(RequisitionItem $requisitionItem): void
    {
        app(CommercialAmountSyncService::class)->syncRequisition((int) $requisitionItem->requisition_id);
    }
}
