<?php

namespace App\Services\Requisition\Actions;

use App\Models\Requisition;
use App\Services\Audit\AuditRecorder;
use App\Services\Requisition\Validators\RequisitionValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria uma nova requisição.
     *
     * @param  array  $data
     * @return Requisition|null
     */
    public function execute(array $data): ?Requisition
    {
        try {
            Log::debug('Iniciando criação de requisição', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = RequisitionValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;
            $validated['stock_consumed'] ??= false;
            $validated['stock_reserved'] ??= false;

            $requisition = Requisition::create($validated);
            $audit = app(AuditRecorder::class);

            $audit->recordModelEvent(
                $requisition,
                'requisition.created',
                "Requisição #{$requisition->number} criada",
                null,
                $audit->snapshot($requisition),
                $this->createdBy,
            );

            Log::info('Requisição criada com sucesso', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'number'         => $requisition->number,
            ]);

            $this->setSuccess();
            return $requisition;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao salvar requisição no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'sql'           => $e->getSql(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar requisição');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        }
    }
}
