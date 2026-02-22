<?php

namespace App\Events\RequisitionItem;

use App\Models\RequisitionItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequisitionItemDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RequisitionItem $item,
        public readonly int $deletedBy,
    ) {}
}
