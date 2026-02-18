<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private ServiceOrder $serviceOrder,
    ) {}

    /**
     * Exclui (soft delete) uma ordem de serviço.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão (soft delete) de ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            if (! $this->validateCanDelete()) {
                return false;
            }

            $result = $this->serviceOrder->delete();

            Log::info('Ordem de serviço excluída (soft delete) com sucesso', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir ordem de serviço. Ela pode estar vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente uma ordem de serviço (force delete).
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            Log::debug('Iniciando exclusão permanente de ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            if (! $this->validateCanForceDelete()) {
                return false;
            }

            $result = $this->serviceOrder->forceDelete();

            Log::info('Ordem de serviço excluída permanentemente com sucesso', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente ordem de serviço. Ela está vinculada a outros registros.');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Valida se a ordem de serviço pode ser excluída (soft delete).
     *
     * @return bool
     */
    private function validateCanDelete(): bool
    {
        // Verifica se já está vinculada a uma fatura
        if ($this->serviceOrder->invoice_id) {
            $this->setError('Não é possível excluir ordem de serviço que está vinculada a uma fatura');

            Log::warning($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'invoice_id'        => $this->serviceOrder->invoice_id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Valida se a ordem de serviço pode ser excluída permanentemente.
     *
     * @return bool
     */
    private function validateCanForceDelete(): bool
    {
        // Não permite exclusão permanente se tiver itens
        $hasItems = DB::table('service_order_items')
            ->where('service_order_id', $this->serviceOrder->id)
            ->exists();

        if ($hasItems) {
            $this->setError('Não é possível excluir permanentemente ordem de serviço que possui itens');

            Log::warning($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
            ]);

            return false;
        }

        return true;
    }
}
