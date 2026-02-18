<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private ServiceOrder $serviceOrder,
    ) {}

    /**
     * Restaura uma ordem de serviço excluída (soft delete).
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('Iniciando restauração de ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            if (! $this->validateCanRestore()) {
                return false;
            }

            $result = $this->serviceOrder->restore();

            Log::info('Ordem de serviço restaurada com sucesso', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao restaurar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao restaurar ordem de serviço');

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
     * Valida se a ordem de serviço pode ser restaurada.
     *
     * @return bool
     */
    private function validateCanRestore(): bool
    {
        // Verifica se está realmente excluída (soft delete)
        if (! $this->serviceOrder->trashed()) {
            $this->setError('A ordem de serviço não está excluída');

            Log::warning($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
            ]);

            return false;
        }

        // Verifica se já existe uma ordem de serviço ativa com o mesmo número na mesma empresa
        $duplicate = DB::table('service_orders')
            ->where('number', $this->serviceOrder->number)
            ->where('company_id', $this->serviceOrder->company_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $this->serviceOrder->id)
            ->exists();

        if ($duplicate) {
            $this->setError('Já existe uma ordem de serviço ativa com este número');    

            Log::warning($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
                'company_id'        => $this->serviceOrder->company_id,
            ]);

            return false;
        }

        return true;
    }
}
