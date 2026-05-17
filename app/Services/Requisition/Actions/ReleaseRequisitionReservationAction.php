<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\Requisition\RequisitionStockWorkflow;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Libera a reserva de estoque dos itens de uma requisição.
 *
 * Itera os itens ainda não consumidos e cria movimentações de RESERVATION_RELEASE
 * via StockMovementService, garantindo rastro de auditoria completo.
 *
 * Deve ser chamado ao cancelar (OPEN → CANCELLED) ou reabrir (CLOSED → OPEN) uma requisição.
 */
class ReleaseRequisitionReservationAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(Requisition $requisition): bool
    {
        try {
            $workflow = app(RequisitionStockWorkflow::class);
            if (! $workflow->releaseReservations($requisition, $this->userId)) {
                $this->setError($workflow->getMessage(), $workflow->getErrors(), $workflow->getStatus(), $workflow->getErrorCode());

                return false;
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao liberar reservas da requisição: ' . $e->getMessage());

            Log::error('ReleaseRequisitionReservationAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
