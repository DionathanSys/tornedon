<?php

namespace App\Services\RequisitionItem\Actions;

use App\Models\RequisitionItem;
use App\Services\RequisitionItem\Validators\RequisitionItemValidator;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateRequisitionItemAction
{
    use HandlesActionResponse, AuthorizesRequisitionItemActions;

    public function __construct(
        private int $updatedBy,
        private RequisitionItem $requisitionItem,
    ) {}

    /**
     * Atualiza um item de requisição existente.
     *
     * @param array $data
     * @return RequisitionItem|null
     */
    public function execute(array $data): ?RequisitionItem
    {
        if (! self::canModifyItems($this->requisitionItem->requisition_id)) {
            $this->setError('Não é permitido atualizar itens desta requisição.');
            return null;
        }

        try {
            $validated = RequisitionItemValidator::validateUpdate($data);

            $validated['updated_by'] = $this->updatedBy;

            $this->requisitionItem->update($validated);
            $this->requisitionItem->refresh();

            $this->setSuccess();
            return $this->requisitionItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'errors'                => $e->errors(),
                'requisition_item_id'   => $this->requisitionItem->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da requisição');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'exception'             => $e->getMessage(),
                'requisition_item_id'   => $this->requisitionItem->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item da requisição');

            Log::error($this->getMessage(), [
                'metodo'                => __METHOD__ . '@' . __LINE__,
                'message'               => $this->getMessage(),
                'error_code'            => $this->getErrorCode(),
                'exception'             => $e->getMessage(),
                'trace'                 => $e->getTraceAsString(),
                'requisition_item_id'   => $this->requisitionItem->id,
            ]);

            return null;
        }
    }
}
