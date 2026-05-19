<?php

namespace App\Services\ServiceOrder;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderSequence;
use App\Services\ServiceOrder\ServiceOrderBillingService;
use App\Services\ServiceOrder\Actions\CancelServiceOrderAction;
use App\Services\ServiceOrder\Actions\CloseServiceOrderAction;
use App\Services\ServiceOrder\Actions\CreateServiceOrderAction;
use App\Services\ServiceOrder\Actions\DeleteServiceOrderAction;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use App\Services\ServiceOrder\Actions\ReopenServiceOrderAction;
use App\Services\ServiceOrder\Actions\UpdateServiceOrderAction;
use App\Services\Shared\CommercialItemDiscountService;
use App\Support\Email\DocumentNotificationDecisionContext;
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
            $serviceOrder = DB::transaction(function () use ($data, $createdBy) {
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

            return $serviceOrder;
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
     * Exclui definitivamente uma ordem de serviço.
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

    /* ==============================
     |  Transições de Estado
     |==============================*/

    /**
     * Encerra uma ordem de serviço (aberta → encerrada).
     */
    public function close(ServiceOrder $serviceOrder, int $userId, ?bool $sendEmail = null): ?ServiceOrder
    {
        $this->resetResponse();

        if ($sendEmail !== null) {
            DocumentNotificationDecisionContext::put('service_order', (int) $serviceOrder->id, $sendEmail);
        }

        try {
            return DB::transaction(function () use ($serviceOrder, $userId) {
                $action = new CloseServiceOrderAction($userId);
                $result = $action->execute($serviceOrder);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'message'          => $this->getMessage(),
                        'error_code'       => $this->getErrorCode(),
                        'service_order_id' => $serviceOrder->id,
                        'errors'           => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de serviço encerrada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao encerrar ordem de serviço');

            Log::error('Erro ao encerrar ordem de serviço via service', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'error_code'       => $this->getErrorCode(),
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Fatura uma ou mais ordens de serviço (encerrada → faturada).
     * Cria uma única Invoice agrupando todos os registros.
     *
     * @param  ServiceOrder|Collection  $records  Uma OS ou coleção de OS
     */
    public function invoice(ServiceOrder|Collection $records, int $userId): ?\App\Models\Invoice
    {
        $this->resetResponse();
        $billing = app(ServiceOrderBillingService::class);
        $invoice = $billing->invoice($records, $userId);

        if ($invoice === null) {
            $this->setError(
                $billing->getMessage(),
                $billing->getErrors(),
                $billing->getStatus(),
                $billing->getErrorCode(),
            );
        } else {
            $this->setSuccess($billing->getMessage(), $billing->getData(), $billing->getStatus());
        }

        return $invoice;
    }

    /**
     * Cancela uma ordem de serviço (aberta → cancelada).
     */
    public function cancel(ServiceOrder $serviceOrder, int $userId): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder, $userId) {
                $action = new CancelServiceOrderAction($userId);
                $result = $action->execute($serviceOrder);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $serviceOrder->id,
                        'message'          => $this->getMessage(),
                        'error_code'       => $this->getErrorCode(),
                        'errors'           => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de serviço cancelada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar ordem de serviço');

            Log::error('Erro ao cancelar ordem de serviço via service', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'error_code'       => $this->getErrorCode(),
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Reabre uma ordem de serviço (encerrada|cancelada → aberta).
     */
    public function reopen(ServiceOrder $serviceOrder, int $userId): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder, $userId) {
                $action = new ReopenServiceOrderAction($userId);
                $result = $action->execute($serviceOrder);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($action->getMessage(), [
                        'metodo'           => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $serviceOrder->id,
                        'message'          => $this->getMessage(),
                        'error_code'       => $this->getErrorCode(),
                        'errors'           => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de serviço reaberta com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao reabrir ordem de serviço');

            Log::error('Erro ao reabrir ordem de serviço via service', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'error_code'       => $this->getErrorCode(),
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /* ==============================
     |  Métodos Auxiliares
     |==============================*/

    /**
     * Aplica valor de desconto distribuído igualmente entre os itens da OS.
     * O desconto é igualmente distribuído e, se o item já possuir desconto,
     * o valor será incrementado. O discount_percentage também será calculado.
     *
     * @param  ServiceOrder  $serviceOrder
     * @param  float         $discountAmount
     * @return bool
     */
    public function applyDiscount(ServiceOrder $serviceOrder, float $discountAmount): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder, $discountAmount): bool {
                $serviceOrder->load('items');
                $items = $serviceOrder->items;
                $result = app(CommercialItemDiscountService::class)->apply(
                    $items,
                    $discountAmount,
                    'Esta ordem de serviço não possui itens.'
                );

                foreach ($items as $item) {
                    Log::debug('Desconto aplicado ao item de ordem de serviço', [
                        'metodo'                    => __METHOD__ . '@' . __LINE__,
                        'service_order_item_id'     => $item->id,
                        'service_order_id'          => $serviceOrder->id,
                        'new_discount_amount'       => (float) $item->discount_amount,
                        'discount_percentage'       => (float) $item->discount_percentage,
                    ]);
                }

                $this->setSuccess('Desconto aplicado com sucesso aos itens.');

                Log::info('Desconto aplicado com sucesso na ordem de serviço', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'total_discount'    => $discountAmount,
                    'item_count'        => $result['item_count'],
                ]);

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao aplicar desconto na ordem de serviço.');

            Log::error('Erro ao aplicar desconto na ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'discount_amount'   => $discountAmount,
                'error_message'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Remove todos os descontos dos itens da OS, zerando discount_amount e discount_percentage.
     *
     * @param  ServiceOrder  $serviceOrder
     * @return bool
     */
    public function clearDiscount(ServiceOrder $serviceOrder): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder): bool {
                $serviceOrder->load('items');
                $items = $serviceOrder->items;
                $itemCount = app(CommercialItemDiscountService::class)->clear(
                    $items,
                    'Esta ordem de serviço não possui itens.'
                );

                foreach ($items as $item) {
                    Log::debug('Desconto removido do item de ordem de serviço', [
                        'metodo'                => __METHOD__ . '@' . __LINE__,
                        'service_order_item_id' => $item->id,
                        'service_order_id'      => $serviceOrder->id,
                    ]);
                }

                $this->setSuccess('Descontos removidos com sucesso.');

                Log::info('Descontos removidos com sucesso da ordem de serviço', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'item_count'        => $itemCount,
                ]);

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao remover descontos da ordem de serviço.');

            Log::error('Erro ao remover descontos da ordem de serviço', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'error_message'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Gera o PDF da ordem de servico em base64.
     */
    public function pdf(ServiceOrder $serviceOrder, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = app(PrintServiceOrderPdfAction::class);
            $pdf    = $action->execute($serviceOrder);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage());
                return null;
            }

            $this->setSuccess('PDF da ordem de serviço gerado.');

            Log::info('ServiceOrderService: PDF gerado com sucesso', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'user_id'          => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da ordem de serviço: ' . $e->getMessage());

            Log::error('ServiceOrderService::pdf', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'user_id'          => $userId,
                'exception'        => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera o preview do PDF da ordem de serviço.
     *
     * @return array{pdf:string}|null
     */
    public function preview(ServiceOrder $serviceOrder, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $pdf = $this->pdf($serviceOrder, $userId);

            if ($pdf === null) {
                return null;
            }

            return ['pdf' => $pdf];
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da ordem de serviço: ' . $e->getMessage());

            Log::error('ServiceOrderService::preview', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'user_id'          => $userId,
                'exception'        => $e->getMessage(),
            ]);

            return null;
        }
    }

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
