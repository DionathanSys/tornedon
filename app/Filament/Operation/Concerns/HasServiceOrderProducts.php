<?php

namespace App\Filament\Operation\Concerns;

use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderRequisitionService;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

trait HasServiceOrderProducts
{
    use HasOperationProducts;

    protected function editableProductDocument(): ServiceOrder
    {
        $order = $this->tenantOrder();
        abort_unless($order, 404);
        abort_unless($order->state()->canEdit(), 403);
        $requisition = $order->requisition;
        abort_if($requisition && ($requisition->company_id !== $order->company_id || ! $requisition->state()->canEdit()), 403);

        return $order;
    }

    protected function productItems(): HasManyThrough
    {
        return $this->editableProductDocument()->requisitionItems();
    }

    protected function resolveProductRequisition(Requisition|ServiceOrder $document): Requisition
    {
        abort_unless($document instanceof ServiceOrder, 403);
        $service = app(ServiceOrderRequisitionService::class);
        $requisition = $service->getOrCreateEditable($document, (int) Auth::id());

        if (! $requisition) {
            throw ValidationException::withMessages(['productData.product_stock_id' => $service->getMessageUser()]);
        }

        return $requisition;
    }

    protected function refreshProductDocument(): void
    {
        $this->loadOrder(preserveForm: true);
    }
}
