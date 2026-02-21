<?php

namespace App\Services\Requisition\Actions;

use App\Models\Requisition;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class RestoreRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private Requisition $requisition,
    ) {}

    /**
     * Restaura uma requisição excluída (soft delete).
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('Iniciando restauração de requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $this->requisition->id,
                'number'         => $this->requisition->number,
            ]);

            $result = $this->requisition->restore();

            Log::info('Requisição restaurada com sucesso', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $this->requisition->id,
                'number'         => $this->requisition->number,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            $this->setError('Erro ao restaurar requisição');

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $this->getMessage(),
                'error_code'     => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error'  => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao restaurar requisição');

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $this->getMessage(),
                'error_code'     => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error'  => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
