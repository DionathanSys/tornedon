<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\QuoteItem;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['created_by'] = Auth::id();
        $data['status'] = Status::QUEUED->value;
        $data['destination_type'] = filled($data['customer_id'] ?? null)
            ? DestinationType::DIRECT_DELIVERY->value
            : DestinationType::STOCK->value;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ProductionOrderService::class);
        $productionOrder = $service->create($data, Auth::id());

        if ($service->hasError() || $productionOrder === null) {
            Log::error('CreateProductionOrder: Falha ao criar ordem de produção', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            $this->halt();
        }

        $this->importQuoteItems($productionOrder);

        return $productionOrder;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    private function importQuoteItems(ProductionOrder $productionOrder): void
    {
        if (! $productionOrder->quote_id || $productionOrder->items()->exists()) {
            return;
        }

        $productionOrder->loadMissing('quote.items.product');

        $quoteItems = $productionOrder->quote?->items
            ?->filter(fn (QuoteItem $item): bool => $item->product_id !== null)
            ->sortBy('sequence')
            ->values();

        if (! $quoteItems || $quoteItems->isEmpty()) {
            return;
        }

        foreach ($quoteItems as $quoteItem) {
            ProductionOrderItem::query()->create([
                'production_order_id' => $productionOrder->id,
                'quote_item_id' => $quoteItem->id,
                'product_id' => $quoteItem->product_id,
                'description' => $quoteItem->resolveDescription(),
                'quantity' => $quoteItem->quantity,
                'unit_price' => $quoteItem->unit_price,
                'unit_cost' => 0,
                'discount_percentage' => $quoteItem->discount_percentage,
                'discount_amount' => $quoteItem->discount_amount,
                'quantity_produced' => 0,
                'quantity_approved' => 0,
                'quantity_rejected' => 0,
                'unit_of_measure' => $quoteItem->unit_of_measure,
                'technical_specifications' => $quoteItem->technical_specifications,
                'sequence' => $quoteItem->sequence ?: (((int) $productionOrder->items()->max('sequence')) + 1),
            ]);
        }
    }
}
