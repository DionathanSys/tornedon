<?php

namespace App\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSequence;
use App\Services\Invoice\InvoiceService;
use App\Services\ServiceOrder\Actions\CancelServiceOrderAction;
use App\Services\ServiceOrder\Actions\CloseServiceOrderAction;
use App\Services\ServiceOrder\Actions\CreateServiceOrderAction;
use App\Services\ServiceOrder\Actions\DeleteServiceOrderAction;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use App\Services\ServiceOrder\Actions\ReopenServiceOrderAction;
use App\Services\ServiceOrder\Actions\RestoreServiceOrderAction;
use App\Services\ServiceOrder\Actions\UpdateServiceOrderAction;
use App\Support\Email\DocumentNotificationDecisionContext;
use App\Traits\HandlesServiceResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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

        $records = $records instanceof ServiceOrder ? new Collection([$records]) : $records;

        // Validação: mesmo cliente
        $customerIds = $records->pluck('customer_id')->unique();
        if ($customerIds->count() > 1) {
            $this->setError('Todos os registros selecionados devem pertencer ao mesmo cliente.');
            return null;
        }

        // Validação: todas encerradas
        $notClosed = $records->filter(fn (ServiceOrder $so) => $so->status !== State::CLOSED);
        if ($notClosed->isNotEmpty()) {
            $this->setError('Apenas ordens de serviço com status "Encerrada" podem ser faturadas.');
            return null;
        }

        // Validação: todas devem possuir itens
        foreach ($records as $record) {
            if ($record->items()->count() === 0) {
                $this->setError("A OS #{$record->number} não possui itens.");
                return null;
            }
        }

        try {
            return DB::transaction(function () use ($records, $userId): \App\Models\Invoice {
                $first = $records->first();

                // 1. Cria Invoice via InvoiceService
                $invoiceService = app(InvoiceService::class);
                $invoice = $invoiceService->create([
                    'customer_id'  => $first->customer_id,
                    'company_id'   => $first->company_id,
                    'invoice_date' => now()->toDateString(),
                ], $userId);

                if ($invoiceService->hasError() || ! $invoice) {
                    $this->setError(
                        'Falha ao criar fatura: ' . $invoiceService->getMessage(),
                        $invoiceService->getErrors(),
                    );
                    throw new \RuntimeException($this->getMessage());
                }

                // 2. Transição de estado e vínculo com a invoice para cada OS
                foreach ($records as $record) {
                    $record->state()->invoice($record, $userId, $invoice->id);
                }

                Log::info('ServiceOrderService: OS(s) faturada(s) com sucesso', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_ids' => $records->pluck('id')->all(),
                    'invoice_id'        => $invoice->id,
                ]);

                $this->setSuccess('Ordem(ns) de serviço faturada(s) com sucesso');
                return $invoice;
            });
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('ServiceOrderService: Transição inválida ao faturar OS', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'errors'            => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar OS no banco de dados');

            Log::error('ServiceOrderService: QueryException ao faturar OS', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'exception'         => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar ordem de serviço');
            }

            Log::error('ServiceOrderService: Erro ao faturar OS', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_ids' => $records->pluck('id')->all(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return null;
        }
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
                // Recarrega itens para garantir dados atualizados
                $serviceOrder->load('items');

                $items = $serviceOrder->items;

                if ($items->isEmpty()) {
                    $this->setError('Esta ordem de serviço não possui itens.');
                    return false;
                }

                // Calcula o valor total dos itens
                $totalItemsValue = $items->sum(function ($item) {
                    return (float) $item->quantity * (float) $item->unit_price;
                });

                // Valida se o desconto não é maior que o total dos itens
                if ($discountAmount > $totalItemsValue) {
                    $this->setError('O desconto não pode ser maior que o valor total dos itens (R$ ' . number_format($totalItemsValue, 2, ',', '.') . ').');
                    return false;
                }

                // Calcula o desconto por item
                $itemCount = $items->count();
                $discountPerItem = round($discountAmount / $itemCount, 2);
                $remainingDiscount = $discountAmount;

                foreach ($items as $index => $item) {
                    // Para o último item, usa o desconto restante para evitar arredondamentos
                    $currentDiscount = $index === $itemCount - 1 ? $remainingDiscount : $discountPerItem;

                    // Incrementa o desconto existente
                    $newDiscountAmount = (float) $item->discount_amount + $currentDiscount;

                    // Calcula o subtotal (quantity * unit_price)
                    $subtotal = (float) $item->quantity * (float) $item->unit_price;

                    // Calcula o percentual de desconto
                    $discountPercentage = $subtotal > 0
                        ? round(($newDiscountAmount / $subtotal) * 100, 2)
                        : 0;

                    // Atualiza o item
                    $item->update([
                        'discount_amount'       => $newDiscountAmount,
                        'discount_percentage'   => $discountPercentage,
                    ]);

                    $remainingDiscount -= $currentDiscount;

                    Log::debug('Desconto aplicado ao item de ordem de serviço', [
                        'metodo'                    => __METHOD__ . '@' . __LINE__,
                        'service_order_item_id'     => $item->id,
                        'service_order_id'          => $serviceOrder->id,
                        'discount_amount_applied'   => $currentDiscount,
                        'new_discount_amount'       => $newDiscountAmount,
                        'discount_percentage'       => $discountPercentage,
                    ]);
                }

                $this->setSuccess('Desconto aplicado com sucesso aos itens.');

                Log::info('Desconto aplicado com sucesso na ordem de serviço', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'total_discount'    => $discountAmount,
                    'item_count'        => $itemCount,
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
                // Recarrega itens para garantir dados atualizados
                $serviceOrder->load('items');

                $items = $serviceOrder->items;

                if ($items->isEmpty()) {
                    $this->setError('Esta ordem de serviço não possui itens.');
                    return false;
                }

                foreach ($items as $item) {
                    $item->update([
                        'discount_amount'       => 0,
                        'discount_percentage'   => 0,
                    ]);

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
                    'item_count'        => $items->count(),
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

            $this->setSuccess('PDF da ordem de servico gerado.');

            Log::info('ServiceOrderService: PDF gerado com sucesso', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $serviceOrder->id,
                'user_id'          => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da ordem de servico: ' . $e->getMessage());

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
     * Gera o preview do PDF da ordem de servico.
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
            $this->setError('Erro ao gerar preview da ordem de servico: ' . $e->getMessage());

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
