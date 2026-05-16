<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private ServiceOrder $serviceOrder,
    ) {}

    /**
     * Exclui definitivamente uma ordem de serviço.
     */
    public function execute(): bool
    {
        try {

            if (! $this->validateCanDelete()) {
                return false;
            }

            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->serviceOrder);
            $result = $this->serviceOrder->delete();

            $audit->recordModelEvent(
                $this->serviceOrder,
                'service_order.deleted',
                "Ordem de serviço #{$this->serviceOrder->number} excluída",
                $before,
                null,
            );

            Log::info('Ordem de serviço excluída definitivamente com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $this->serviceOrder->id,
                'number' => $this->serviceOrder->number,
            ]);

            $this->setSuccess();

            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir ordem de serviço. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'message_error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'message_error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        if ($this->serviceOrder->invoice_id) {
            $this->setError('Não é possível excluir ordem de serviço que está vinculada a uma fatura');

            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'invoice_id' => $this->serviceOrder->invoice_id,
            ]);

            return false;
        }

        if ($this->serviceOrder->requisition()->exists()) {
            $this->setError('Não é possível excluir ordem de serviço que possui requisição vinculada');

            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
            ]);

            return false;
        }

        return true;
    }
}
