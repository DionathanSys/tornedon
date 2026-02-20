<?php

namespace App\Services\ServiceOrderItem\Actions;

use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\Validators\ServiceOrderItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateServiceOrderItemAction
{
    use HandlesActionResponse;

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
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao criar item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
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
            ]);

            return null;
        }
    }
}
