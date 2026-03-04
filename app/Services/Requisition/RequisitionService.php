<?php

namespace App\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Models\RequisitionSequence;
use App\Services\Requisition\Actions\CancelRequisitionAction;
use App\Services\Requisition\Actions\CloseRequisitionAction;
use App\Services\Requisition\Actions\CreateRequisitionAction;
use App\Services\Requisition\Actions\DeleteRequisitionAction;
use App\Services\Requisition\Actions\InvoiceRequisitionAction;
use App\Services\Requisition\Actions\ReopenRequisitionAction;
use App\Services\Requisition\Actions\RestoreRequisitionAction;
use App\Services\Requisition\Actions\UpdateRequisitionAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequisitionService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Lista todas as requisições de uma empresa.
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('Listando requisições', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = Requisition::where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['salesperson_id'])) {
            $query->where('salesperson_id', $filters['salesperson_id']);
        }

        if (isset($filters['service_order_id'])) {
            $query->where('service_order_id', $filters['service_order_id']);
        }

        if (isset($filters['equipment_id'])) {
            $query->where('equipment_id', $filters['equipment_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('observations', 'like', "%{$search}%");
            });
        }

        return $query->with([
            'customer',
            'company',
            'salesperson',
            'serviceOrder',
            'equipment',
            'invoice',
            'items',
        ])->orderBy('sale_date', 'desc')->get();
    }

    /**
     * Busca uma requisição pelo ID.
     */
    public function find(int $id, ?int $companyId = null): ?Requisition
    {
        Log::debug('Buscando requisição', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $id,
            'company_id'     => $companyId,
        ]);

        $query = Requisition::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with([
            'customer',
            'company',
            'salesperson',
            'serviceOrder',
            'equipment',
            'invoice',
            'items',
        ])->first();
    }

    /**
     * Busca a requisição vinculada a um orçamento pelo ID do orçamento.
     */
    public function findByQuoteId(int $quoteId): ?Requisition
    {
        Log::debug('Buscando requisição por quote_id', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $quoteId,
        ]);

        return Requisition::where('quote_id', $quoteId)->first();
    }

    /* ==============================
     |  Operações de Escrita
     |==============================*/

    /**
     * Cria uma nova requisição.
     */
    public function create(array $data, int $createdBy): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                if (empty($data['number']) && isset($data['company_id'])) {
                    $data['number'] = $this->generateNumber($data['company_id']);
                }

                $data['status'] = $data['status'] ?? Status::OPEN->value;

                $action = new CreateRequisitionAction($createdBy);
                $requisition = $action->execute($data);

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
                        'data'           => $data,
                        'user_id'        => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição criada com sucesso');

                Log::info('Requisição criada com sucesso via service', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'number'         => $requisition->number,
                ]);

                return $requisition;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar requisição');

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
     * Atualiza uma requisição existente.
     */
    public function update(Requisition $requisition, array $data, int $updatedBy): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $data, $updatedBy) {
                $action = new UpdateRequisitionAction($updatedBy, $requisition);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'errors'         => $action->getErrors(),
                        'data'           => $data,
                        'user_id'        => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição atualizada com sucesso');

                Log::info('Requisição atualizada com sucesso via service', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'number'         => $requisition->number,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar requisição');

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
                'data'           => $data,
                'user_id'        => $updatedBy,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) uma requisição.
     */
    public function delete(Requisition $requisition): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition) {
                $action = new DeleteRequisitionAction($requisition);
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
                        'requisition_id' => $requisition->id,
                        'message'        => $action->getMessage(),
                        'error_code'     => $action->getErrorCode(),
                        'errors'         => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Requisição excluída com sucesso');

                Log::info('Requisição excluída com sucesso via service', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'number'         => $requisition->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir requisição');

            Log::error('Erro ao excluir requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente uma requisição (force delete).
     */
    public function forceDelete(Requisition $requisition): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition) {
                $action = new DeleteRequisitionAction($requisition);
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
                        'requisition_id' => $requisition->id,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Requisição excluída permanentemente com sucesso');

                Log::info('Requisição excluída permanentemente com sucesso via service', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'number'         => $requisition->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir permanentemente requisição');

            Log::error('Erro ao excluir permanentemente requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Restaura uma requisição excluída (soft delete).
     */
    public function restore(Requisition $requisition): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition) {
                $action = new RestoreRequisitionAction($requisition);
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
                        'requisition_id' => $requisition->id,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Requisição restaurada com sucesso');

                Log::info('Requisição restaurada com sucesso via service', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'number'         => $requisition->number,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao restaurar requisição');

            Log::error('Erro ao restaurar requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /* ==============================
     |  Transições de Estado
     |==============================*/

    /**
     * Encerra uma requisição (aberta → encerrada).
     */
    public function close(Requisition $requisition, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $userId) {
                $action = new CloseRequisitionAction($userId);
                $result = $action->execute($requisition);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'requisition_id' => $requisition->id,
                        'errors'         => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição encerrada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao encerrar requisição');

            Log::error('Erro ao encerrar requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Fatura uma requisição (encerrada → faturada).
     */
    public function invoice(Requisition $requisition, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $userId) {
                $action = new InvoiceRequisitionAction($userId);
                $result = $action->execute($requisition);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'errors'         => $action->getErrors(),
                        'requisition_id' => $requisition->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição faturada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao faturar requisição');

            Log::error('Erro ao faturar requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Cancela uma requisição (aberta → cancelada).
     */
    public function cancel(Requisition $requisition, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $userId) {
                $action = new CancelRequisitionAction($userId);
                $result = $action->execute($requisition);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'errors'         => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição cancelada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar requisição');

            Log::error('Erro ao cancelar requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Reabre uma requisição (encerrada|cancelada → aberta).
     */
    public function reopen(Requisition $requisition, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $userId) {
                $action = new ReopenRequisitionAction($userId);
                $result = $action->execute($requisition);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'errors'         => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição reaberta com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao reabrir requisição');

            Log::error('Erro ao reabrir requisição via service', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_code'     => $this->getErrorCode(),
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /* ==============================
     |  Métodos Auxiliares
     |==============================*/

    /**
     * Gera o próximo número de requisição para a empresa.
     * Usa lock pessimista para evitar duplicidade.
     */
    private function generateNumber(int $companyId): string
    {
        $sequence = RequisitionSequence::lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $companyId],
                ['last_number' => 0]
            );

        $sequence->increment('last_number');

        return str_pad($sequence->last_number, 5, '0', STR_PAD_LEFT);
    }
}
