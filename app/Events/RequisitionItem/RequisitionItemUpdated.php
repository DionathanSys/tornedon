<?php

namespace App\Events\RequisitionItem;

use App\Models\RequisitionItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequisitionItemUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RequisitionItem $item,
        public readonly int             $oldProductId,
        public readonly string          $oldUnitOfMeasure,
        public readonly float           $oldQuantity,
        public readonly float           $oldBaseQuantity,
        public readonly float           $oldUnitPrice,
        public readonly int             $updatedBy,
    ) {}
}
