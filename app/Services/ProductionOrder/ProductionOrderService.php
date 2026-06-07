<?php

namespace App\Services\ProductionOrder;

use App\Domain\Exceptions\ProductionOrder\InvalidStateTransitionException;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Services\ProductionOrder\Actions\CancelProductionAction;
use App\Services\ProductionOrder\Actions\CompleteProduction;
use App\Services\ProductionOrder\Actions\CreateProductionOrder;
use App\Services\ProductionOrder\Actions\GenerateRequisitionFromProductionAction;
use App\Services\ProductionOrder\Actions\PrintProductionOrderPdfAction;
use App\Services\ProductionOrder\Actions\ReturnToProductionAction;
use App\Services\ProductionOrder\Actions\SendToQcAction;
use App\Services\ProductionOrder\Actions\StartProduction;
use App\Services\ProductionOrder\Actions\UpdateProgress;
use App\Services\Requisition\RequisitionService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionOrderService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?ProductionOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductionOrder($createdBy);
                $productionOrder = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('ProductionOrderService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de produção criada com sucesso');

                Log::info('ProductionOrderService: Ordem de produção criada com sucesso', [
                    'metodo'       => __METHOD__ . '@' . __LINE__,
                    'production_order_id'     => $productionOrder->id,
                    'production_order_number' => $productionOrder->production_order_number,
                ]);

                return $productionOrder;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar ordem de produção');

            Log::error('ProductionOrderService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function start(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new StartProduction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Produção iniciada');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function updateProgress(ProductionOrder $productionOrder, array $itemsProgress, int $userId): bool
    {
        $action = new UpdateProgress($userId);
        $result = $action->execute($productionOrder, $itemsProgress);

        if ($action->isSuccess()) {
            $this->setSuccess('Progresso atualizado com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function complete(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new CompleteProduction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Produção concluída com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function sendToQc(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new SendToQcAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem enviada para controle de qualidade');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function returnToProduction(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new ReturnToProductionAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem retornada para produção');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function cancel(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new CancelProductionAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem de produção cancelada');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    /**
     * Gera uma requisição a partir de uma ordem de produção concluída.
     * Os itens aprovados da PO são convertidos em itens da requisição.
     * A requisição fica vinculada à PO (bidirecional).
     */
    public function generateRequisition(ProductionOrder $productionOrder, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($productionOrder, $userId) {
                $action = new GenerateRequisitionFromProductionAction($userId);
                $requisition = $action->execute($productionOrder);

                if ($action->hasError() || $requisition === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('ProductionOrderService: ' . $this->getMessage(), [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'error_code'          => $this->getErrorCode(),
                        'errors'              => $action->getErrors(),
                        'production_order_id' => $productionOrder->id,
                        'user_id'             => $userId,
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição gerada com sucesso a partir da ordem de produção');

                Log::info('ProductionOrderService: Requisição gerada com sucesso', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'production_order_id' => $productionOrder->id,
                    'requisition_id'      => $requisition->id,
                ]);

                return $requisition;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar requisição a partir da ordem de produção');

            Log::error('ProductionOrderService: ' . $this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'production_order_id' => $productionOrder->id,
                'user_id'             => $userId,
            ]);

            return null;
        }
    }

    /**
     * Fatura uma ou mais OPs concluídas: cria uma única Invoice agrupando todos os registros.
     *
     * @param  ProductionOrder|\Illuminate\Database\Eloquent\Collection  $records
     */
    public function invoice(ProductionOrder|\Illuminate\Database\Eloquent\Collection $records, int $userId): ?\App\Models\Invoice
    {
        $this->resetResponse();

        $records = $records instanceof ProductionOrder
            ? new \Illuminate\Database\Eloquent\Collection([$records])
            : $records;

        // Validação: mesmo cliente
        $customerIds = $records->pluck('customer_id')->unique();
        if ($customerIds->count() > 1) {
            $this->setError('Todos os registros selecionados devem pertencer ao mesmo cliente.');
            return null;
        }

        // Validação: todas concluídas
        $notCompleted = $records->filter(fn (ProductionOrder $po) => $po->status !== \App\Enum\ProductionOrder\Status::COMPLETED);
        if ($notCompleted->isNotEmpty()) {
            $this->setError('Apenas ordens de produção com status "Concluída" podem ser faturadas.');
            return null;
        }

        foreach ($records as $record) {
            if ($record->items()->count() === 0) {
                $this->setError("A OP #{$record->production_order_number} não possui itens.");
                return null;
            }

            if (! $record->requisition_id) {
                $this->setError("A OP #{$record->production_order_number} precisa gerar requisição antes do faturamento.");
                return null;
            }
        }

        try {
            return DB::transaction(function () use ($records, $userId): \App\Models\Invoice {
                $requisitionService = app(RequisitionService::class);
                $requisitions = new \Illuminate\Database\Eloquent\Collection();

                foreach ($records as $record) {
                    $requisition = $record->requisition()->with('items.product')->first();

                    if (! $requisition) {
                        $this->setError("Requisição da OP #{$record->production_order_number} não encontrada.");
                        throw new \RuntimeException($this->getMessage());
                    }

                    if ($requisition->status === \App\Enum\Requisition\Status::OPEN) {
                        $closed = $requisitionService->close($requisition, $userId, false);

                        if ($requisitionService->hasError() || ! $closed) {
                            $this->setError(
                                'Falha ao encerrar requisição da OP #' . $record->production_order_number . ': ' . $requisitionService->getMessage(),
                                $requisitionService->getErrors(),
                                422,
                                $requisitionService->getErrorCode(),
                            );

                            throw new \RuntimeException($this->getMessage());
                        }

                        $requisition = $closed->fresh(['items.product']);
                    }

                    $requisitions->push($requisition);
                }

                $invoice = $requisitionService->invoice($requisitions, $userId);

                if ($requisitionService->hasError() || ! $invoice) {
                    $this->setError(
                        'Falha ao faturar requisições vinculadas às OPs: ' . $requisitionService->getMessage(),
                        $requisitionService->getErrors(),
                        422,
                        $requisitionService->getErrorCode(),
                    );

                    throw new \RuntimeException($this->getMessage());
                }

                foreach ($records as $record) {
                    $record->state()->invoice($invoice->id);
                }

                Log::info('ProductionOrderService: OP(s) faturada(s) com sucesso', [
                    'metodo'               => __METHOD__ . '@' . __LINE__,
                    'production_order_ids' => $records->pluck('id')->all(),
                    'invoice_id'           => $invoice->id,
                ]);

                $this->setSuccess('Ordem(ns) de produção faturada(s) com sucesso');
                return $invoice;
            });
        } catch (InvalidStateTransitionException $e) {
            $this->setError($e->getMessage());

            Log::warning('ProductionOrderService: Transição inválida ao faturar OP', [
                'metodo'               => __METHOD__ . '@' . __LINE__,
                'production_order_ids' => $records->pluck('id')->all(),
                'exception'            => $e->getMessage(),
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar OP no banco de dados');

            Log::error('ProductionOrderService: QueryException ao faturar OP', [
                'metodo'               => __METHOD__ . '@' . __LINE__,
                'production_order_ids' => $records->pluck('id')->all(),
                'exception'            => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar ordem de produção');
            }

            Log::error('ProductionOrderService: Erro ao faturar OP', [
                'metodo'               => __METHOD__ . '@' . __LINE__,
                'error_code'           => $this->getErrorCode(),
                'exception'            => $e->getMessage(),
                'trace'                => $e->getTraceAsString(),
                'production_order_ids' => $records->pluck('id')->all(),
                'user_id'              => $userId,
            ]);

            return null;
        }
    }

    /**
     * Gera o PDF da ordem de producao em base64.
     */
    public function pdf(ProductionOrder $productionOrder, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = new PrintProductionOrderPdfAction();
            $pdf    = $action->execute($productionOrder);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage());
                return null;
            }

            $this->setSuccess('PDF da ordem de producao gerado.');

            Log::info('ProductionOrderService: PDF gerado com sucesso', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'user_id'             => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da ordem de producao: ' . $e->getMessage());

            Log::error('ProductionOrderService::pdf', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'user_id'             => $userId,
                'exception'           => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera o preview do PDF da ordem de producao.
     *
     * @return array{pdf:string}|null
     */
    public function preview(ProductionOrder $productionOrder, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $pdf = $this->pdf($productionOrder, $userId);

            if ($pdf === null) {
                return null;
            }

            return ['pdf' => $pdf];
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da ordem de producao: ' . $e->getMessage());

            Log::error('ProductionOrderService::preview', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'user_id'             => $userId,
                'exception'           => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Aplica valor de desconto distribuído igualmente entre os itens da OP.
     * O desconto é igualmente distribuído e, se o item já possuir desconto,
     * o valor será incrementado. O discount_percentage também será calculado.
     *
     * @param  ProductionOrder  $productionOrder
     * @param  float            $discountAmount
     * @return bool
     */
    /**
     * Recupera informações do formulário do ERP para a OP
     *
     * @param  array  $data
     * @return \Illuminate\Http\Client\Response
     */
    private function fetchErpFormInfo(array $data)
    {
        // Implementar lógica de integração com ERP
    }
}
