<?php

namespace App\Services\Requisition\Actions;

use App\Models\Requisition;
use App\Services\Audit\AuditRecorder;
use App\Services\Requisition\Validators\RequisitionValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int         $updatedBy,
        private Requisition $requisition,
    ) {}

    /**
     * Atualiza uma requisição existente.
     *
     * @param  array  $data
     * @return Requisition|null
     */
    public function execute(array $data): ?Requisition
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->requisition);

            Log::debug('Iniciando atualização de requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $this->requisition->id,
                'user_id'        => $this->updatedBy,
                'data'           => $data,
            ]);

            $validated = RequisitionValidator::validateUpdate(
                $data,
                $this->requisition->id,
                $this->requisition->company_id
            );

            // Remove campos imutáveis
            unset($validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->requisition->update($validated);
            $this->requisition->refresh();

            $audit->recordModelEvent(
                $this->requisition,
                'requisition.updated',
                "Requisição #{$this->requisition->number} atualizada",
                $before,
                $audit->snapshot($this->requisition),
                $this->updatedBy,
            );

            Log::info('Requisição atualizada com sucesso', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $this->requisition->id,
                'number'         => $this->requisition->number,
                'user_id'        => $this->updatedBy,
            ]);

            $this->setSuccess();
            return $this->requisition;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $this->getMessage(),
                'error_code'     => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'errors'         => $e->errors(),
                'data'           => $data,
                'user_id'        => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar requisição no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $this->getMessage(),
                'error_code'     => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error'  => $e->getMessage(),
                'data'           => $data,
                'user_id'        => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar requisição');

            Log::error($this->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $this->getMessage(),
                'error_code'     => $this->getErrorCode(),
                'requisition_id' => $this->requisition->id,
                'message_error'  => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
                'data'           => $data,
                'user_id'        => $this->updatedBy,
            ]);

            return null;
        }
    }
}
