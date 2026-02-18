<?php

namespace App\Services\ServiceOrder;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderSequence;
use App\Services\ServiceOrder\Actions\CreateServiceOrderAction;
use App\Services\ServiceOrder\Actions\DeleteServiceOrderAction;
use App\Services\ServiceOrder\Actions\RestoreServiceOrderAction;
use App\Services\ServiceOrder\Actions\UpdateServiceOrderAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceOrderService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Lista todas as ordens de serviço de uma empresa.
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('Listando ordens de serviço', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = ServiceOrder::where('company_id', $companyId);

        // Filtros
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['technician_id'])) {
            $query->where('technician_id', $filters['technician_id']);
        }

        if (isset($filters['equipment_id'])) {
            $query->where('equipment_id', $filters['equipment_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('solution', 'like', "%{$search}%");
            });
        }

        return $query->with([
            'customer',
            'company',
            'equipment',
            'technician',
            'supervisor',
            'salesperson',
            'invoice',
            'items',
        ])->orderBy('order_date', 'desc')->get();
    }

    /**
     * Lista ordens de serviço com paginação.
     */
    public function paginate(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        Log::debug('Listando ordens de serviço com paginação', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
            'per_page'   => $perPage,
        ]);

        $query = ServiceOrder::where('company_id', $companyId);

        // Filtros
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['technician_id'])) {
            $query->where('technician_id', $filters['technician_id']);
        }

        if (isset($filters['equipment_id'])) {
            $query->where('equipment_id', $filters['equipment_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('solution', 'like', "%{$search}%");
            });
        }

        return $query->with([
            'customer',
            'company',
            'equipment',
            'technician',
            'supervisor',
            'salesperson',
            'invoice',
            'items',
        ])->orderBy('order_date', 'desc')
            ->paginate($perPage);
    }

    /**
     * Busca uma ordem de serviço pelo ID.
     */
    public function find(int $id, ?int $companyId = null): ?ServiceOrder
    {
        Log::debug('Buscando ordem de serviço', [
            'metodo'            => __METHOD__ . '@' . __LINE__,
            'service_order_id'  => $id,
            'company_id'        => $companyId,
        ]);

        $query = ServiceOrder::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with([
            'customer',
            'company',
            'equipment',
            'technician',
            'supervisor',
            'salesperson',
            'invoice',
            'items.service',
        ])->first();
    }

    /**
     * Busca uma ordem de serviço pelo número.
     */
    public function findByNumber(string $number, int $companyId): ?ServiceOrder
    {
        Log::debug('Buscando ordem de serviço por número', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'number'     => $number,
            'company_id' => $companyId,
        ]);

        return ServiceOrder::where('number', $number)
            ->where('company_id', $companyId)
            ->with([
                'customer',
                'company',
                'equipment',
                'technician',
                'supervisor',
                'salesperson',
                'invoice',
                'items.service',
            ])->first();
    }

    /* ==============================
     |  Operações de Escrita
     |==============================*/

    /**
     * Cria uma nova ordem de serviço.
     */
    public function create(array $data, int $createdBy): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                // Gera número automaticamente se não fornecido
                if (empty($data['number']) && isset($data['company_id'])) {
                    $data['number'] = $this->generateNumber($data['company_id']);
                }

                $action = new CreateServiceOrderAction($createdBy);
                $serviceOrder = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                        'data'              => $data,
                        'user_id'           => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de serviço criada com sucesso');

                Log::info('Ordem de serviço criada com sucesso via service', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'number'            => $serviceOrder->number,
                ]);

                return $serviceOrder;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    /**
     * Atualiza uma ordem de serviço existente.
     */
    public function update(ServiceOrder $serviceOrder, array $data, int $updatedBy): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder, $data, $updatedBy) {
                $action = new UpdateServiceOrderAction($updatedBy, $serviceOrder);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_id'  => $serviceOrder->id,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'errors'            => $action->getErrors(),
                        'data'              => $data,
                        'user_id'           => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de serviço atualizada com sucesso');

                Log::info('Ordem de serviço atualizada com sucesso via service', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'number'            => $serviceOrder->number,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'error_code'        => $this->getErrorCode(),
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'data'              => $data,
                'user_id'           => $updatedBy,  
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) uma ordem de serviço.
     */
    public function delete(ServiceOrder $serviceOrder): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder) {
                $action = new DeleteServiceOrderAction($serviceOrder);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_id'  => $serviceOrder->id,
                        'message'           => $action->getMessage(),
                        'error_code'        => $action->getErrorCode(),
                        'errors'            => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Ordem de serviço excluída com sucesso');

                Log::info('Ordem de serviço excluída com sucesso via service', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'number'            => $serviceOrder->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir ordem de serviço');

            Log::error('Erro ao excluir ordem de serviço via service', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'error_code'        => $this->getErrorCode(),
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente uma ordem de serviço (force delete).
     */
    public function forceDelete(ServiceOrder $serviceOrder): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder) {
                $action = new DeleteServiceOrderAction($serviceOrder);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_id'  => $serviceOrder->id,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Ordem de serviço excluída permanentemente com sucesso');

                Log::info('Ordem de serviço excluída permanentemente com sucesso via service', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'number'            => $serviceOrder->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir permanentemente ordem de serviço');

            Log::error('Erro ao excluir permanentemente ordem de serviço via service', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'error_code'        => $this->getErrorCode(),
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Restaura uma ordem de serviço excluída (soft delete).
     */
    public function restore(ServiceOrder $serviceOrder): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder) {
                $action = new RestoreServiceOrderAction($serviceOrder);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'service_order_id'  => $serviceOrder->id,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Ordem de serviço restaurada com sucesso');

                Log::info('Ordem de serviço restaurada com sucesso via service', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'number'            => $serviceOrder->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao restaurar ordem de serviço');

            Log::error('Erro ao restaurar ordem de serviço via service', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'error_code'        => $this->getErrorCode(),
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /* ==============================
     |  Métodos Auxiliares
     |==============================*/

    /**
     * Gera o próximo número de ordem de serviço para a empresa.
     * Usa lock pessimista para evitar duplicidade.
     *
     * @param  int  $companyId
     * @return string
     */
    private function generateNumber(int $companyId): string
    {
        $sequence = ServiceOrderSequence::lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $companyId],
                ['last_number' => 0]
            );

        $sequence->increment('last_number');

        return str_pad($sequence->last_number, 5, '0', STR_PAD_LEFT);
    }
}
