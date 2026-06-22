<?php

namespace App\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\State;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\RequisitionSequence;
use App\Services\Requisition\RequisitionBillingService;
use App\Services\Requisition\Actions\CancelRequisitionAction;
use App\Services\Requisition\Actions\CloseRequisitionAction;
use App\Services\Requisition\Actions\CreateRequisitionAction;
use App\Services\Requisition\Actions\DeleteRequisitionAction;
use App\Services\Requisition\Actions\PrintRequisitionPdfAction;
use App\Services\Requisition\Actions\ReopenRequisitionAction;
use App\Services\Requisition\Actions\UpdateRequisitionAction;
use App\Services\Requisition\RequisitionStockService;
use App\Services\Requisition\RequisitionStockWorkflow;
use App\Services\Shared\CommercialItemDiscountService;
use App\Support\Email\DocumentNotificationDecisionContext;
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
     * Exclui definitivamente uma requisição.
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
     * Desvincula a requisição da ordem de serviço associada.
     */
    public function unlinkServiceOrder(Requisition $requisition, int $updatedBy): ?Requisition
    {
        $this->resetResponse();

        $serviceOrder = $requisition->serviceOrder;

        if ($serviceOrder === null) {
            $this->setError('A requisição não possui ordem de serviço vinculada.');

            return null;
        }

        if ($requisition->status !== Status::OPEN) {
            $this->setError('Só é possível desvincular requisições abertas.');

            return null;
        }

        if ($serviceOrder->status !== State::OPEN) {
            $this->setError('Só é possível desvincular quando a ordem de serviço vinculada estiver aberta.');

            return null;
        }

        try {
            return DB::transaction(function () use ($requisition, $updatedBy): Requisition {
                Log::info('RequisitionService: desvinculando ordem de serviço da requisição', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'service_order_id' => $requisition->service_order_id,
                    'user_id' => $updatedBy,
                ]);

                $requisition->update([
                    'service_order_id' => null,
                    'updated_by' => $updatedBy,
                ]);

                $this->setSuccess('Requisição desvinculada da ordem de serviço com sucesso');

                return $requisition->fresh();
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao desvincular requisição da ordem de serviço.');

            Log::error('RequisitionService: erro ao desvincular ordem de serviço da requisição', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'service_order_id' => $requisition->service_order_id,
                'user_id' => $updatedBy,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
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
        $billing = app(RequisitionBillingService::class);
        $invoice = $billing->invoice(
            $records,
            $userId,
            function (Collection $records, Invoice $invoice, int $userId): void {
                $this->attachRecordsToInvoice($records, $invoice, $userId);
            }
        );

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

    public function invoiceIntoExisting(Requisition|Collection $records, int $userId, Invoice $invoice): ?Invoice
    {
        $this->resetResponse();
        $billing = app(RequisitionBillingService::class);
        $updatedInvoice = $billing->invoiceIntoExisting(
            $records,
            $userId,
            $invoice,
            function (Collection $records, Invoice $invoice, int $userId): void {
                $this->attachRecordsToInvoice($records, $invoice, $userId);
            }
        );

        if ($updatedInvoice === null) {
            $this->setError(
                $billing->getMessage(),
                $billing->getErrors(),
                $billing->getStatus(),
                $billing->getErrorCode(),
            );
        } else {
            $this->setSuccess($billing->getMessage(), $billing->getData(), $billing->getStatus());
        }

        return $updatedInvoice;
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
        $workflow = app(RequisitionStockWorkflow::class);

        if (! $workflow->processStockExits($requisition, $userId)) {
            throw new \RuntimeException($workflow->getMessage());
        }
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
                $requisition->load('items');
                $items = $requisition->items;
                $result = app(CommercialItemDiscountService::class)->apply(
                    $items,
                    $discountAmount,
                    'Esta requisição não possui itens.'
                );

                foreach ($items as $item) {
                    Log::debug('Desconto aplicado ao item de requisição', [
                        'metodo'                    => __METHOD__ . '@' . __LINE__,
                        'requisition_item_id'       => $item->id,
                        'requisition_id'            => $requisition->id,
                        'new_discount_amount'       => (float) $item->discount_amount,
                        'discount_percentage'       => (float) $item->discount_percentage,
                    ]);
                }

                $this->setSuccess('Desconto aplicado com sucesso aos itens.');

                Log::info('Desconto aplicado com sucesso na requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'total_discount' => $discountAmount,
                    'item_count'     => $result['item_count'],
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
                $requisition->load('items');
                $items = $requisition->items;
                $itemCount = app(CommercialItemDiscountService::class)->clear(
                    $items,
                    'Esta requisição não possui itens.'
                );

                foreach ($items as $item) {
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
                    'item_count'     => $itemCount,
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
