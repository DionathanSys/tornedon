<?php

namespace App\Services\Requisition\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReopenRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($requisition);

            Log::debug('ReopenRequisitionAction: Reabrindo requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
            ]);

            DB::transaction(function () use ($requisition) {
                // Libera eventuais reservas de estoque antes de reabrir
                $releaseAction = new ReleaseRequisitionReservationAction($this->userId);
                if (! $releaseAction->execute($requisition)) {
                    throw new \Exception($releaseAction->getMessage());
                }

                $requisition->state()->reopen($requisition, $this->userId);

                // Marca que as reservas foram liberadas — serão recriadas no próximo fechamento
                $requisition->update(['stock_reserved' => false]);

                $requisition->refresh();
            });

            $audit->recordModelEvent(
                $requisition,
                'requisition.reopened',
                "Requisição #{$requisition->number} reaberta",
                $before,
                $audit->snapshot($requisition),
                $this->userId,
            );

            $this->setSuccess();
            return $requisition;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('ReopenRequisitionAction: Transição inválida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao reabrir requisição no banco de dados');

            Log::error('ReopenRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao reabrir requisição: ' . $e->getMessage());

            Log::error('ReopenRequisitionAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
