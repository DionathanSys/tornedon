<?php

namespace App\Services\ServiceOrder;

use App\Enum\Requisition\Status as RequisitionStatus;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Requisition\RequisitionService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceServiceOrderWorkflow
{
    use HandlesServiceResponse;

    private ?Invoice $invoice = null;

    public function __construct(
        private readonly ServiceOrderService $serviceOrderService,
        private readonly RequisitionService $requisitionService,
    ) {}

    /**
     * @param  ServiceOrder|Collection<int, ServiceOrder>  $serviceOrders
     */
    public function execute(ServiceOrder|Collection $serviceOrders, int $userId): bool
    {
        $this->resetResponse();
        $this->invoice = null;

        $serviceOrders = $serviceOrders instanceof ServiceOrder
            ? new Collection([$serviceOrders])
            : $serviceOrders;

        try {
            return DB::transaction(function () use ($serviceOrders, $userId): bool {
                $linkedRequisitions = new Collection();

                foreach ($serviceOrders as $serviceOrder) {
                    $linkedRequisition = $serviceOrder->requisition()->first();

                    Log::debug('InvoiceServiceOrderWorkflow: iniciando faturamento sincronizado', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $serviceOrder->id,
                        'linked_requisition_id' => $linkedRequisition?->id,
                    ]);

                    if (! $linkedRequisition instanceof Requisition) {
                        continue;
                    }

                    if ($linkedRequisition->status === RequisitionStatus::OPEN) {
                        $closedRequisition = $this->requisitionService->close($linkedRequisition->fresh(), $userId, false);

                        if ($this->requisitionService->hasError() || ! $closedRequisition) {
                            return $this->propagateError($this->requisitionService);
                        }

                        $linkedRequisition = $closedRequisition;
                    }

                    if ($linkedRequisition->status === RequisitionStatus::CANCELLED) {
                        $this->setError('Não é possível faturar a ordem de serviço porque a requisição vinculada está cancelada.');

                        return false;
                    }

                    if ($linkedRequisition->status === RequisitionStatus::INVOICED) {
                        $this->setError('Não é possível faturar a ordem de serviço porque a requisição vinculada já está faturada.');

                        return false;
                    }

                    $linkedRequisitions->push($linkedRequisition->fresh());
                }

                $invoice = $this->serviceOrderService->invoice($serviceOrders->fresh(), $userId);

                if ($this->serviceOrderService->hasError() || ! $invoice) {
                    return $this->propagateError($this->serviceOrderService);
                }

                if ($linkedRequisitions->isNotEmpty()) {
                    $invoice = $this->requisitionService->invoiceIntoExisting($linkedRequisitions->fresh(), $userId, $invoice);

                    if ($this->requisitionService->hasError() || ! $invoice) {
                        return $this->propagateError($this->requisitionService);
                    }
                }

                $this->invoice = $invoice;
                $this->setSuccess('Ordem de serviço faturada com sucesso');

                return true;
            });
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar ordem de serviço e requisição vinculada.');
            }

            Log::error('InvoiceServiceOrderWorkflow: erro ao faturar fluxo sincronizado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $serviceOrders->pluck('id')->all(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function invoice(): ?Invoice
    {
        return $this->invoice;
    }

    private function propagateError(object $service): bool
    {
        $this->setError(
            $service->getMessage(),
            $service->getErrors(),
            $service->getStatus(),
            $service->getErrorCode(),
        );

        return false;
    }
}
