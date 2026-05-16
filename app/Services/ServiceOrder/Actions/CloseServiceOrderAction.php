<?php

namespace App\Services\ServiceOrder\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CloseServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(ServiceOrder $order): ?ServiceOrder
    {
        try {
            if (! $order->items()->exists()) {
                $this->setError('Não é possível encerrar ordem de serviço sem itens.');

                Log::warning('CloseServiceOrderAction: ordem sem itens', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $order->id,
                ]);

                return null;
            }

            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($order);

            $order->state()->close($order, $this->userId);

            $order->refresh();
            $audit->recordModelEvent(
                $order,
                'service_order.closed',
                "Ordem de serviço #{$order->number} encerrada",
                $before,
                $audit->snapshot($order),
                $this->userId,
            );

            $this->setSuccess();
            return $order;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('CloseServiceOrderAction: Transição inválida', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'errors'           => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao encerrar ordem de serviço no banco de dados');

            Log::error('CloseServiceOrderAction: QueryException', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'exception'        => $e->getMessage(),
            ]);

            return null;
        }
    }
}
