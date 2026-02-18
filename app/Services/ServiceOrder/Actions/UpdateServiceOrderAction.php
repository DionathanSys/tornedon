<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\Validators\ServiceOrderValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int          $updatedBy,
        private ServiceOrder $serviceOrder,
    ) {}

    /**
     * Atualiza uma ordem de serviço existente.
     *
     * @param  array  $data
     * @return ServiceOrder|null
     */
    public function execute(array $data): ?ServiceOrder
    {
        try {
            Log::debug('Iniciando atualização de ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'user_id'           => $this->updatedBy,
                'data'              => $data,
            ]);

            $validated = ServiceOrderValidator::validateUpdate(
                $data,
                $this->serviceOrder->id,
                $this->serviceOrder->company_id
            );

            // Remove campos imutáveis
            unset($validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->serviceOrder->update($validated);

            Log::info('Ordem de serviço atualizada com sucesso', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $this->serviceOrder->id,
                'number'            => $this->serviceOrder->number,
                'user_id'           => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->serviceOrder;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'errors'            => $e->errors(),
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar ordem de serviço no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
                $this->setError('Erro inesperado ao atualizar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'service_order_id'  => $this->serviceOrder->id,
                'message_error'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;
        }
    }
}
