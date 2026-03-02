<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Libera a reserva de estoque criada ao encerrar uma requisição.
 *
 * Busca os StockMovements do tipo RESERVATION vinculados à requisição
 * e cria movimentos de RESERVATION_RELEASE correspondentes.
 *
 * Deve ser chamado ao cancelar ou reabrir uma requisição que estava encerrada.
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
            $stockMovementService = app(StockMovementService::class);

            // Busca todas as reservas ativas vinculadas a esta requisição (via morphMany)
            $reservations = $requisition->stockMovements()
                ->where('type', Type::RESERVATION->value)
                ->get();

            if ($reservations->isEmpty()) {
                Log::info('ReleaseRequisitionReservationAction: Nenhuma reserva encontrada', [
                    'requisition_id' => $requisition->id,
                ]);
                $this->setSuccess();
                return true;
            }

            foreach ($reservations as $reservation) {
                $release = $stockMovementService->create([
                    'product_stock_id' => $reservation->product_stock_id,
                    'product_id'       => $reservation->product_id,
                    'company_id'       => $reservation->company_id,
                    'type'             => Type::RESERVATION_RELEASE->value,
                    'quantity'         => (float) $reservation->quantity,
                    'unit_price'       => (float) ($reservation->unit_price ?? 0),
                    'reason'           => 'Liberação de reserva — requisição #' . $requisition->number,
                    'source_type'      => 'requisition',
                    'source_id'        => $requisition->id,
                    'observations'     => $reservation->observations,
                ], $this->userId);

                if (! $release) {
                    Log::error('ReleaseRequisitionReservationAction: Falha ao criar liberação', [
                        'reservation_id' => $reservation->id,
                        'product_id'     => $reservation->product_id,
                        'requisition_id' => $requisition->id,
                        'error'          => $stockMovementService->getMessage(),
                    ]);
                    $this->setError('Falha ao liberar reserva de estoque para produto #' . $reservation->product_id);
                    return false;
                }

                Log::info('ReleaseRequisitionReservationAction: Reserva liberada', [
                    'reservation_id' => $reservation->id,
                    'release_id'     => $release->id,
                    'product_id'     => $reservation->product_id,
                    'quantity'       => $reservation->quantity,
                    'requisition_id' => $requisition->id,
                ]);
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
