<?php

namespace App\Services\ServiceOrder;

use App\Enum\Requisition\Status as RequisitionStatus;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Services\Requisition\RequisitionService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloseServiceOrderWorkflow
{
    use HandlesServiceResponse;

    private ?Invoice $invoice = null;

    private bool $closedLinkedRequisition = false;

    public function __construct(
        private readonly ServiceOrderService $serviceOrderService,
        private readonly RequisitionService $requisitionService,
    ) {}

    public function execute(ServiceOrder $serviceOrder, int $userId, bool $sendEmail, bool $shouldInvoiceAfterClose = false): bool
    {
        $this->resetResponse();
        $this->invoice = null;
        $this->closedLinkedRequisition = false;

        try {
            return DB::transaction(function () use ($serviceOrder, $userId, $sendEmail, $shouldInvoiceAfterClose): bool {
                $linkedRequisition = $serviceOrder->requisition()->first();

                Log::debug('CloseServiceOrderWorkflow: iniciando fechamento sincronizado', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'linked_requisition_id' => $linkedRequisition?->id,
                ]);

                if ($linkedRequisition instanceof Requisition && $linkedRequisition->status === RequisitionStatus::OPEN) {
                    $closedRequisition = $this->requisitionService->close($linkedRequisition->fresh(), $userId, $sendEmail);

                    if ($this->requisitionService->hasError() || ! $closedRequisition) {
                        return $this->propagateError($this->requisitionService);
                    }

                    $this->closedLinkedRequisition = true;
                }

                $closedServiceOrder = $this->serviceOrderService->close($serviceOrder, $userId, $sendEmail);

                if ($this->serviceOrderService->hasError() || ! $closedServiceOrder) {
                    return $this->propagateError($this->serviceOrderService);
                }

                if ($shouldInvoiceAfterClose) {
                    $invoiceWorkflow = app(InvoiceServiceOrderWorkflow::class);
                    $result = $invoiceWorkflow->execute($closedServiceOrder->fresh(), $userId);

                    if (! $result) {
                        return $this->propagateError($invoiceWorkflow);
                    }

                    $this->invoice = $invoiceWorkflow->invoice();
                    $this->setSuccess('Ordem de serviço encerrada e faturada com sucesso');

                    return true;
                }

                $this->setSuccess('Ordem de serviço encerrada com sucesso');

                return true;
            });
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao encerrar ordem de serviço e requisição vinculada.');
            }

            Log::error('CloseServiceOrderWorkflow: erro ao encerrar fluxo sincronizado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function closedLinkedRequisition(): bool
    {
        return $this->closedLinkedRequisition;
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
