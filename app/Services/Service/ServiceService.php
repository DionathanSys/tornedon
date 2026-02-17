<?php

namespace App\Services\Service;

use App\Models\Service;
use App\Services\Service\Actions\CreateServiceAction;
use App\Services\Service\Actions\DeleteServiceAction;
use App\Services\Service\Actions\RestoreServiceAction;
use App\Services\Service\Actions\UpdateServiceAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Lista todos os serviços de uma empresa.
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('Listando serviços', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = Service::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('nbs_code', 'like', "%{$search}%")
                  ->orWhere('cnae_code', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    /**
     * Lista serviços com paginação.
     */
    public function paginate(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        Log::debug('Paginando serviços', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
            'per_page'   => $perPage,
        ]);

        $query = Service::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('nbs_code', 'like', "%{$search}%")
                  ->orWhere('cnae_code', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Busca um serviço pelo ID.
     */
    public function find(int $id, ?int $companyId = null): ?Service
    {
        Log::debug('Buscando serviço por ID', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'id'         => $id,
            'company_id' => $companyId,
        ]);

        $query = Service::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }

    /* ==============================
     |  Mutações
     |==============================*/

    /**
     * Cria um novo serviço.
     */
    public function create(array $data, int $createdBy): ?Service
    {
        $this->resetResponse();

        try {
            Log::debug('ServiceService: Iniciando criação de serviço', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $createdBy,
                'data'    => $data,
            ]);

            return DB::transaction(function () use ($data, $createdBy) {
                $action  = new CreateServiceAction($createdBy);
                $service = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Serviço criado com sucesso');

                Log::info('ServiceService: Serviço criado com sucesso', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'service_id' => $service->id,
                ]);

                return $service;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar criação do serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }

    /**
     * Atualiza um serviço existente.
     */
    public function update(Service $service, array $data, int $updatedBy): ?Service
    {
        $this->resetResponse();

        try {
            Log::debug('ServiceService: Iniciando atualização de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
                'user_id'    => $updatedBy,
                'data'       => $data,
            ]);

            return DB::transaction(function () use ($service, $data, $updatedBy) {
                $action = new UpdateServiceAction($updatedBy, $service);
                $result = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'errors'         => $action->getErrors(),
                        'service_id'     => $service->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Serviço atualizado com sucesso');

                Log::info('ServiceService: Serviço atualizado com sucesso', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'service_id' => $service->id,
                ]);

                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $service->id,
                'data'       => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um serviço.
     */
    public function delete(Service $service): bool
    {
        $this->resetResponse();

        try {
            Log::debug('ServiceService: Iniciando exclusão de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
            ]);

            return DB::transaction(function () use ($service) {
                $action = new DeleteServiceAction($service);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                        'service_id'     => $service->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Serviço excluído com sucesso');

                Log::info('ServiceService: Serviço excluído com sucesso', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'service_id' => $service->id,
                ]);

                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão do serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $service->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um serviço.
     */
    public function forceDelete(Service $service): bool
    {
        $this->resetResponse();

        try {
            Log::debug('ServiceService: Iniciando exclusão permanente de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
            ]);

            return DB::transaction(function () use ($service) {
                $action = new DeleteServiceAction($service);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                        'service_id'     => $service->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Serviço excluído permanentemente com sucesso');

                Log::info('ServiceService: Serviço excluído permanentemente com sucesso', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'service_id' => $service->id,
                ]);

                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão permanente do serviço', ['error' => [$e->getMessage()]]);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $service->id,
            ]);

            return false;
        }
    }

    /**
     * Restaura um serviço excluído.
     */
    public function restore(Service $service): bool
    {
        $this->resetResponse();

        try {
            Log::debug('ServiceService: Iniciando restauração de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
            ]);

            return DB::transaction(function () use ($service) {
                $action = new RestoreServiceAction($service);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                        'service_id'     => $service->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Serviço restaurado com sucesso');

                Log::info('ServiceService: Serviço restaurado com sucesso', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'service_id' => $service->id,
                ]);

                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar restauração do serviço', ['error' => [$e->getMessage()]]);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $service->id,
            ]);

            return false;
        }
    }

    /**
     * Ativa ou desativa um serviço.
     */
    public function toggleActive(Service $service, bool $active, int $updatedBy): ?Service
    {
        Log::debug('ServiceService: Alternando status ativo de serviço', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'service_id' => $service->id,
            'active'     => $active,
            'user_id'    => $updatedBy,
        ]);

        return $this->update($service, ['is_active' => $active], $updatedBy);
    }
}
