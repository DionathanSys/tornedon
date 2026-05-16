<?php

namespace App\Services\Requisition\Actions;

use App\Models\Requisition;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private Requisition $requisition,
    ) {}

    /**
     * Exclui definitivamente uma requisição.
     */
    public function execute(): bool
    {
        try {

            if (! $this->validateCanDelete()) {
                return false;
            }

            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->requisition);
            $result = $this->requisition->delete();

            $audit->recordModelEvent(
                $this->requisition,
                'requisition.deleted',
                "Requisição #{$this->requisition->number} excluída",
                $before,
                null,
            );

            Log::info('Requisição excluída definitivamente com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $this->requisition->id,
                'number' => $this->requisition->number,
            ]);

            $this->setSuccess();

            return $result;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir requisição. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir requisição');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function validateCanDelete(): bool
    {
        if ($this->requisition->invoice_id) {
            $this->setError('Não é possível excluir requisição que está vinculada a uma fatura');

            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'invoice_id' => $this->requisition->invoice_id,
            ]);

            return false;
        }

        if ($this->requisition->items()->where('stock_consumed', true)->exists()) {
            $this->setError('Não é possível excluir requisição que possui itens com estoque consumido');

            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
            ]);

            return false;
        }

        return true;
    }
}
