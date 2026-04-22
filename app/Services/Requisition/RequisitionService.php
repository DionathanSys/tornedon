<?php

namespace App\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Exceptions\DomainValidationException;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\RequisitionSequence;
use App\Services\Invoice\InvoiceService;
use App\Services\ProductStock\ProductStockService;
use App\Services\Requisition\Actions\CancelRequisitionAction;
use App\Services\Requisition\Actions\CloseRequisitionAction;
use App\Services\Requisition\Actions\CreateRequisitionAction;
use App\Services\Requisition\Actions\DeleteRequisitionAction;
use App\Services\Requisition\Actions\PrintRequisitionPdfAction;
use App\Services\Requisition\Actions\ReopenRequisitionAction;
use App\Services\Requisition\Actions\RestoreRequisitionAction;
use App\Services\Requisition\Actions\UpdateRequisitionAction;
use App\Services\StockMovement\StockMovementService;
use App\Support\Email\DocumentNotificationDecisionContext;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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
            $requisition = DB::transaction(function () use ($data, $createdBy) {
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

            return $requisition;
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
    public function close(Requisition $requisition, int $userId, ?bool $sendEmail = null): ?Requisition
    {
        $this->resetResponse();

        if ($sendEmail !== null) {
            DocumentNotificationDecisionContext::put('requisition', (int) $requisition->id, $sendEmail);
        }

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
     * Fatura uma ou mais requisições (encerrada → faturada).
     * Cria uma única Invoice agrupando todos os registros.
     *
     * @param  Requisition|Collection  $records  Uma requisição ou coleção de requisições
     */
    public function invoice(Requisition|Collection $records, int $userId): ?\App\Models\Invoice
    {
        $this->resetResponse();

        $records = $records instanceof Requisition ? new Collection([$records]) : $records;

        // Validação: mesmo cliente
        $customerIds = $records->pluck('customer_id')->unique();
        if ($customerIds->count() > 1) {
            $this->setError('Todos os registros selecionados devem pertencer ao mesmo cliente.');
            return null;
        }

        // Validação: todas encerradas
        $notClosed = $records->filter(fn (Requisition $req) => $req->status !== Status::CLOSED);
        if ($notClosed->isNotEmpty()) {
            $this->setError('Apenas requisições com status "Encerrada" podem ser faturadas.');
            return null;
        }

        // Validação: todas devem possuir itens
        foreach ($records as $record) {
            if ($record->items()->count() === 0) {
                $this->setError("A requisição #{$record->number} não possui itens.");
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

                $this->attachRecordsToInvoice($records, $invoice, $userId);

                Log::info('RequisitionService: Requisição(ões) faturada(s) com sucesso', [
                    'metodo'          => __METHOD__ . '@' . __LINE__,
                    'requisition_ids' => $records->pluck('id')->all(),
                    'invoice_id'      => $invoice->id,
                ]);

                $this->setSuccess('Requisição(ões) faturada(s) com sucesso');
                return $invoice;
            });
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('RequisitionService: Transição inválida ao faturar', [
                'metodo'          => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'errors'          => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar requisição no banco de dados');

            Log::error('RequisitionService: QueryException ao faturar', [
                'metodo'          => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'exception'       => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar requisição');
            }

            Log::error('RequisitionService: Erro ao faturar', [
                'metodo'          => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'error_code'      => $this->getErrorCode(),
                'exception'       => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function invoiceIntoExisting(Requisition|Collection $records, int $userId, Invoice $invoice): ?Invoice
    {
        $this->resetResponse();

        $records = $records instanceof Requisition ? new Collection([$records]) : $records;

        if ($records->isEmpty()) {
            $this->setError('Nenhuma requisição informada para faturamento.');
            return null;
        }

        $customerIds = $records->pluck('customer_id')->push($invoice->customer_id)->unique();
        if ($customerIds->count() > 1) {
            $this->setError('A requisição deve pertencer ao mesmo cliente da fatura da ordem de serviço.');
            return null;
        }

        if ((int) $invoice->company_id !== (int) $records->first()->company_id) {
            $this->setError('A requisição deve pertencer à mesma empresa da fatura da ordem de serviço.');
            return null;
        }

        $notClosed = $records->filter(fn (Requisition $req) => $req->status !== Status::CLOSED);
        if ($notClosed->isNotEmpty()) {
            $this->setError('Apenas requisições com status "Encerrada" podem ser faturadas na mesma fatura da ordem de serviço.');
            return null;
        }

        foreach ($records as $record) {
            if ($record->invoice_id !== null) {
                $this->setError("A requisição #{$record->number} já está vinculada a outra fatura.");
                return null;
            }

            if ($record->items()->count() === 0) {
                $this->setError("A requisição #{$record->number} não possui itens.");
                return null;
            }
        }

        try {
            return DB::transaction(function () use ($records, $userId, $invoice): Invoice {
                $this->attachRecordsToInvoice($records, $invoice, $userId);

                Log::info('RequisitionService: Requisição(ões) faturada(s) em fatura existente', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_ids' => $records->pluck('id')->all(),
                    'invoice_id' => $invoice->id,
                ]);

                $this->setSuccess('Requisição(ões) faturada(s) com sucesso na mesma fatura da ordem de serviço.');

                return $invoice->fresh();
            });
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('RequisitionService: Transição inválida ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'errors' => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar requisição na fatura da ordem de serviço.');

            Log::error('RequisitionService: QueryException ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar requisição na fatura da ordem de serviço.');
            }

            Log::error('RequisitionService: Erro ao faturar em fatura existente', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_ids' => $records->pluck('id')->all(),
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Para cada item ainda não consumido:
     *  1. Cria movimentação EXIT (saída física decrementa quantity_total)
     *  2. Cria movimentação RESERVATION_RELEASE (libera a reserva, decrementa quantity_reserved)
     *  3. Marca o item como consumido
     * Ao final, marca a requisição como stock_consumed.
     */
    private function processStockExits(Requisition $requisition, int $userId): void
    {
        $stockMovementService = app(StockMovementService::class);
        $productStockService  = app(ProductStockService::class);

        $items = $requisition->items()
            ->whereNull('stock_consumed_at')
            ->get();

        if ($items->isEmpty()) {
            Log::info('RequisitionService: Nenhum item pendente de saída de estoque', [
                'requisition_id' => $requisition->id,
            ]);
            return;
        }

        foreach ($items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $item->product;

            if (! $product || ! $product->has_stock_control) {
                $item->update([
                    'stock_consumed'    => true,
                    'stock_consumed_at' => now(),
                ]);
                continue;
            }

            $productStock = $productStockService->findByProductId(
                $item->product_id,
                $requisition->company_id
            );

            if (! $productStock) {
                Log::warning('RequisitionService: ProductStock não encontrado', [
                    'product_id'     => $item->product_id,
                    'requisition_id' => $requisition->id,
                    'item_id'        => $item->id,
                ]);
                throw new \Exception(
                    'Estoque não encontrado para o produto #' . $item->product_id
                );
            }

            $baseData = [
                'product_stock_id' => $productStock->id,
                'product_id'       => $item->product_id,
                'company_id'       => $requisition->company_id,
                'quantity'         => (float) $item->quantity,
                'unit_price'       => (float) ($item->unit_price ?? 0),
                'source_type'      => 'requisition',
                'source_id'        => $requisition->id,
                'observations'     => $item->observations,
            ];

            $exit = $stockMovementService->create(array_merge($baseData, [
                'type'   => Type::EXIT->value,
                'reason' => 'Saída por faturamento — requisição #' . $requisition->number,
            ]), $userId);

            if (! $exit) {
                throw new \Exception(
                    'Falha ao criar saída de estoque para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            $release = $stockMovementService->create(array_merge($baseData, [
                'type'   => Type::RESERVATION_RELEASE->value,
                'reason' => 'Liberação de reserva por faturamento — requisição #' . $requisition->number,
            ]), $userId);

            if (! $release) {
                throw new \Exception(
                    'Falha ao liberar reserva de estoque para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            $item->update([
                'stock_consumed'    => true,
                'stock_consumed_at' => now(),
            ]);

            Log::info('RequisitionService: Saída de estoque processada', [
                'product_id'     => $item->product_id,
                'quantity'       => $item->quantity,
                'exit_id'        => $exit->id,
                'release_id'     => $release->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        $requisition->update(['stock_consumed' => true]);
    }

    private function attachRecordsToInvoice(Collection $records, Invoice $invoice, int $userId): void
    {
        foreach ($records as $record) {
            $record->state()->invoice($record, $userId, $invoice->id);
            $this->processStockExits($record, $userId);
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
     * Aplica valor de desconto distribuído igualmente entre os itens da requisição.
     * O desconto é igualmente distribuído e, se o item já possuir desconto,
     * o valor será incrementado. O discount_percentage também será calculado.
     *
     * @param  Requisition  $requisition
     * @param  float        $discountAmount
     * @return bool
     */
    public function applyDiscount(Requisition $requisition, float $discountAmount): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition, $discountAmount): bool {
                // Recarrega itens para garantir dados atualizados
                $requisition->load('items');

                $items = $requisition->items;

                if ($items->isEmpty()) {
                    $this->setError('Esta requisição não possui itens.');
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

                    Log::debug('Desconto aplicado ao item de requisição', [
                        'metodo'                    => __METHOD__ . '@' . __LINE__,
                        'requisition_item_id'       => $item->id,
                        'requisition_id'            => $requisition->id,
                        'discount_amount_applied'   => $currentDiscount,
                        'new_discount_amount'       => $newDiscountAmount,
                        'discount_percentage'       => $discountPercentage,
                    ]);
                }

                $this->setSuccess('Desconto aplicado com sucesso aos itens.');

                Log::info('Desconto aplicado com sucesso na requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'total_discount' => $discountAmount,
                    'item_count'     => $itemCount,
                ]);

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao aplicar desconto na requisição.');

            Log::error('Erro ao aplicar desconto na requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'discount_amount'   => $discountAmount,
                'error_message'     => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Remove todos os descontos dos itens da requisição, zerando discount_amount e discount_percentage.
     *
     * @param  Requisition  $requisition
     * @return bool
     */
    public function clearDiscount(Requisition $requisition): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisition): bool {
                // Recarrega itens para garantir dados atualizados
                $requisition->load('items');

                $items = $requisition->items;

                if ($items->isEmpty()) {
                    $this->setError('Esta requisição não possui itens.');
                    return false;
                }

                foreach ($items as $item) {
                    $item->update([
                        'discount_amount'       => 0,
                        'discount_percentage'   => 0,
                    ]);

                    Log::debug('Desconto removido do item de requisição', [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'requisition_item_id' => $item->id,
                        'requisition_id'      => $requisition->id,
                    ]);
                }

                $this->setSuccess('Descontos removidos com sucesso.');

                Log::info('Descontos removidos com sucesso da requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'item_count'     => $items->count(),
                ]);

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao remover descontos da requisição.');

            Log::error('Erro ao remover descontos da requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error_message'  => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Gera o PDF da requisicao em base64.
     */
    public function pdf(Requisition $requisition, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = app(PrintRequisitionPdfAction::class);
            $pdf    = $action->execute($requisition);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage());
                return null;
            }

            $this->setSuccess('PDF da requisicao gerado.');

            Log::info('RequisitionService: PDF gerado com sucesso', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da requisicao: ' . $e->getMessage());

            Log::error('RequisitionService::pdf', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $userId,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera o preview do PDF da requisicao.
     *
     * @return array{pdf:string}|null
     */
    public function preview(Requisition $requisition, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $pdf = $this->pdf($requisition, $userId);

            if ($pdf === null) {
                return null;
            }

            return ['pdf' => $pdf];
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da requisicao: ' . $e->getMessage());

            Log::error('RequisitionService::preview', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $userId,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        }
    }

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
