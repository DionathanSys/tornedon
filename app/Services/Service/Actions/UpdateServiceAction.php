<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Services\Service\Validators\ServiceValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private int     $updatedBy,
        private Service $service,
    ) {}

    /**
     * Atualiza um serviço existente.
     *
     * @param  array  $data
     * @return Service|null
     */
    public function execute(array $data): ?Service
    {
        try {
            Log::debug('Iniciando atualização de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'user_id'    => $this->updatedBy,
                'data'       => $data,
            ]);

            $validated = ServiceValidator::validateUpdate($data, $this->service->id, $this->service->company_id);

            // Remove campos imutáveis
            unset($validated['service_code'], $validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->service->update($validated);

            Log::info('Serviço atualizado com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
                'user_id'    => $this->updatedBy,
                'campos'     => array_keys($validated),
            ]);

            $this->setSuccess();
            return $this->service->refresh();

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do serviço', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'service_id' => $this->service->id,
                'data'       => $data,
                'user_id'    => $this->updatedBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar serviço no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'service_id' => $this->service->id,
                'data'       => $data,
                'user_id'    => $this->updatedBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $this->service->id,
                'data'       => $data,
                'user_id'    => $this->updatedBy,
            ]);

            return null;
        }
    }
}
