<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Services\Service\ServiceCodeService;
use App\Services\Service\Validators\ServiceValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateServiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo serviço.
     *
     * @param  array  $data
     * @return Service|null
     */
    public function execute(array $data): ?Service
    {
        try {
            Log::debug('Iniciando criação de serviço', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = ServiceValidator::validateCreate($data);

            $validated['created_by'] = $this->createdBy;

            $service = Service::create($validated);

            Log::info('Serviço criado com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
                'name'       => $service->name,
                'company_id' => $service->company_id,
                'user_id'    => $this->createdBy,
                'service_code' => $service->service_code,
            ]);

            $this->setSuccess();
            return $service;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do serviço', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000')
                ? 'Já existe um serviço com estas características'
                : 'Erro ao criar serviço no banco de dados';

            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        }
    }
}
