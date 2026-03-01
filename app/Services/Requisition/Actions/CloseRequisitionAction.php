<?php

namespace App\Services\Requisition\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloseRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            Log::debug('CloseRequisitionAction: Encerrando requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
            ]);

            return DB::transaction(function () use ($requisition) {
                // 1. Transição de estado (open → closed)
                $requisition->state()->close($requisition, $this->userId);

                // 2. Consome o estoque gerando movimentações de saída
                $consumeAction = new ConsumeRequisitionStockAction($this->userId);
                $consumed = $consumeAction->execute($requisition);

                if (! $consumed) {
                    throw new \RuntimeException(
                        'Falha ao consumir estoque: ' . $consumeAction->getMessage()
                    );
                }

                $requisition->refresh();

                $this->setSuccess();
                return $requisition;
            });

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('CloseRequisitionAction: Transição inválida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao encerrar requisição no banco de dados');

            Log::error('CloseRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro ao encerrar requisição: ' . $e->getMessage());

            Log::error('CloseRequisitionAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
