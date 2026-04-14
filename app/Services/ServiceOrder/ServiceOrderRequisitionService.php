<?php

namespace App\Services\ServiceOrder;

use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Requisition\RequisitionService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class ServiceOrderRequisitionService
{
    use HandlesServiceResponse;

    public function findLinked(ServiceOrder $serviceOrder): ?Requisition
    {
        return $serviceOrder->requisition()->first();
    }

    public function getOrCreateEditable(ServiceOrder $serviceOrder, int $userId): ?Requisition
    {
        $this->resetResponse();

        $requisition = $this->findLinked($serviceOrder);

        if ($requisition !== null) {
            if (! $requisition->state()->canEdit()) {
                $this->setError(
                    sprintf(
                        'A requisição #%s vinculada à OS não pode receber novos produtos porque está %s. Reabra ou ajuste a requisição para continuar.',
                        $requisition->number,
                        mb_strtolower($requisition->status->description())
                    )
                );

                Log::warning('ServiceOrderRequisitionService: requisição vinculada não editável', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'requisition_id' => $requisition->id,
                    'requisition_status' => $requisition->status->value,
                ]);

                return null;
            }

            return $requisition;
        }

        $requisitionService = app(RequisitionService::class);

        $requisition = $requisitionService->create([
            'customer_id' => $serviceOrder->customer_id,
            'company_id' => $serviceOrder->company_id,
            'service_order_id' => $serviceOrder->id,
            'equipment_id' => $serviceOrder->equipment_id,
            'salesperson_id' => $serviceOrder->salesperson_id,
            'sale_date' => $serviceOrder->order_date?->toDateString() ?? now()->toDateString(),
            'payment_method' => $serviceOrder->payment_method?->value,
            'payment_condition' => $serviceOrder->payment_condition?->value,
            'delivery_date' => $serviceOrder->scheduled_date?->toDateString(),
            'delivery_address' => $serviceOrder->location,
            'observations' => $serviceOrder->customer_observations,
        ], $userId);

        if ($requisitionService->hasError() || $requisition === null) {
            $this->setError(
                $requisitionService->getMessage(),
                $requisitionService->getErrors(),
                $requisitionService->getStatus(),
                $requisitionService->getErrorCode(),
            );

            Log::error('ServiceOrderRequisitionService: erro ao criar requisição vinculada', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'error_code' => $requisitionService->getErrorCode(),
                'message' => $requisitionService->getMessage(),
                'errors' => $requisitionService->getErrors(),
            ]);

            return null;
        }

        Log::info('ServiceOrderRequisitionService: requisição vinculada criada', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_order_id' => $serviceOrder->id,
            'requisition_id' => $requisition->id,
        ]);

        return $requisition;
    }
}
