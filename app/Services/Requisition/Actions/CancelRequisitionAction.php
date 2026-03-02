<?php

namespace App\Services\Requisition\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CancelRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            Log::debug('CancelRequisitionAction: Cancelando requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
            ]);

            DB::transaction(function () use ($requisition) {
                $requisition->state()->cancel($requisition, $this->userId);

                // Libera eventuais reservas de estoque criadas ao encerrar a requisição
                $releaseAction = new ReleaseRequisitionReservationAction($this->userId);
                if (! $releaseAction->execute($requisition)) {
                    throw new \Exception($releaseAction->getMessage());
                }

                $requisition->refresh();
            });

            $this->setSuccess();
            return $requisition;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('CancelRequisitionAction: Transição inválida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao cancelar requisição no banco de dados');

            Log::error('CancelRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar requisição: ' . $e->getMessage());

            Log::error('CancelRequisitionAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
