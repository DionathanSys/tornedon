<?php

namespace App\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Traits\HandlesServiceResponse;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DuplicateServiceOrderWorkflow
{
    use HandlesServiceResponse;

    public function __construct(private readonly ServiceOrderService $service) {}

    public function execute(ServiceOrder $source, int $userId): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            $duplicated = DB::transaction(function () use ($source, $userId): ServiceOrder {
                $source = ServiceOrder::query()->where('company_id', $source->company_id)->whereKey($source->getKey())->with('items')->firstOrFail();
                $data = $source->only([
                    'customer_id', 'company_id', 'priority', 'type', 'solution', 'equipment_id', 'location',
                    'customer_observations', 'general_observations', 'internal_observations', 'items_received',
                    'technician_observations', 'estimated_hours', 'value_km', 'distance_km', 'travel_value',
                    'payment_method', 'payment_condition', 'technician_id', 'supervisor_id', 'salesperson_id',
                    'warranty_expires_at', 'requires_approval', 'additional_info',
                ]);

                foreach (['priority', 'type', 'payment_method', 'payment_condition'] as $field) {
                    if ($data[$field] instanceof BackedEnum) {
                        $data[$field] = $data[$field]->value;
                    }
                }

                $duplicated = $this->service->create([
                    ...$data,
                    'order_date' => now()->toDateString(),
                    'status' => State::OPEN->value,
                    'scheduled_date' => null, 'limit_date' => null, 'completion_date' => null,
                    'actual_hours' => null, 'approved_by_customer' => false, 'approved_at' => null,
                    'customer_rating' => null, 'customer_feedback' => null, 'invoice_id' => null,
                ], $userId);

                if ($this->service->hasError() || ! $duplicated) {
                    $this->setError($this->service->getMessage(), $this->service->getErrors(), $this->service->getStatus(), $this->service->getErrorCode());
                    throw new \RuntimeException('Não foi possível criar a OS duplicada.');
                }

                foreach ($source->items as $item) {
                    ServiceOrderItem::query()->create([
                        ...$item->only(['service_id', 'quantity', 'unit_price', 'discount_percentage', 'discount_amount', 'observations', 'additional_info']),
                        'service_order_id' => $duplicated->id,
                        'created_by' => $userId,
                    ]);
                }

                return $duplicated->fresh(['items.service']);
            });
        } catch (\Throwable $exception) {
            report($exception);

            if ($this->getMessage() === '') {
                $this->setError('Não foi possível duplicar a ordem de serviço.');
            }

            return null;
        }

        $this->setSuccess('Ordem de serviço duplicada com sucesso.');
        Log::info('Ordem de serviço duplicada', ['original_service_order' => $source->id, 'new_service_order' => $duplicated->id, 'user_id' => $userId]);

        return $duplicated;
    }
}
