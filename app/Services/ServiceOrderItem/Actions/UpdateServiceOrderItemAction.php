<?php

namespace App\Services\ServiceOrderItem\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\Validators\ServiceOrderItemValidator;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceOrderItemAction
{
    use HandlesActionResponse, AuthorizesServiceOrderItemActions;

    public function __construct(
        private int $updatedBy,
        private ServiceOrderItem $serviceOrderItem,
    ) {}

    /**
     * Atualiza um item de ordem de serviço existente.
     *
     * @param array $data
     * @return ServiceOrderItem|null
     */
    public function execute(array $data): ?ServiceOrderItem
    {
        if (! self::canModifyItems($this->serviceOrderItem->service_order_id)) {
            $this->setError('Não é permitido atualizar itens desta ordem de serviço.');
            return null;
        }

        try {
            $validated = ServiceOrderItemValidator::validateUpdate($data);

            $validated['updated_by'] = $this->updatedBy;

            $this->serviceOrderItem->update($validated);
            $this->serviceOrderItem->refresh();

            $this->setSuccess();
            return $this->serviceOrderItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'errors'              => $e->errors(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return null;
        }
    }
}
