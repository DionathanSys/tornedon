<?php

namespace App\Services\ServiceOrderItem\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrderItem\Validators\ServiceOrderItemValidator;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateServiceOrderItemAction
{
    use HandlesActionResponse, AuthorizesServiceOrderItemActions;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo item de ordem de serviço.
     *
     * @param array $data
     * @return ServiceOrderItem|null
     */
    public function execute(array $data): ?ServiceOrderItem
    {   
        if(! isset($data['service_order_id']) || ! self::canModifyItems($data['service_order_id'])) {
            $this->setError('Não é permitido adicionar itens a esta ordem de serviço.');
            return null;
        }

        try {
            $validated = ServiceOrderItemValidator::validateCreate($data);

            $validated['created_by'] = $this->createdBy;

            $serviceOrderItem = ServiceOrderItem::create($validated);

            $this->setSuccess();
            return $serviceOrderItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao criar item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }
}
