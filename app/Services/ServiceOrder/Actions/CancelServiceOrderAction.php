<?php

namespace App\Services\ServiceOrder\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CancelServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(ServiceOrder $order): ?ServiceOrder
    {
        try {
            Log::debug('CancelServiceOrderAction: Cancelando ordem de serviço', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'user_id'          => $this->userId,
            ]);

            $order->state()->cancel($order, $this->userId);

            $order->refresh();

            $this->setSuccess();
            return $order;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('CancelServiceOrderAction: Transição inválida', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'errors'           => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao cancelar ordem de serviço no banco de dados');

            Log::error('CancelServiceOrderAction: QueryException', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'exception'        => $e->getMessage(),
            ]);

            return null;
        }
    }
}
