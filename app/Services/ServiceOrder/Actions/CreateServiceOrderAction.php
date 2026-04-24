<?php

namespace App\Services\ServiceOrder\Actions;

use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use App\Services\ServiceOrder\Validators\ServiceOrderValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria uma nova ordem de serviço.
     *
     * @param  array  $data
     * @return ServiceOrder|null
     */
    public function execute(array $data): ?ServiceOrder
    {
        try {
            Log::debug('Iniciando criação de ordem de serviço', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $data = app(CustomerPaymentDefaultsResolver::class)->resolve(
                (int) ($data['company_id'] ?? 0),
                isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                $data['payment_method'] ?? null,
                $data['payment_condition'] ?? null,
            ) + $data;

            $validated = ServiceOrderValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $serviceOrder = ServiceOrder::create($validated);
            $audit = app(AuditRecorder::class);

            $audit->recordModelEvent(
                $serviceOrder,
                'service_order.created',
                "Ordem de serviço #{$serviceOrder->number} criada",
                null,
                $audit->snapshot($serviceOrder),
                $this->createdBy,
            );

            Log::info('Ordem de serviço criada com sucesso', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'service_order_id'  => $serviceOrder->id,
                'number'            => $serviceOrder->number,
            ]);

            $this->setSuccess();
            return $serviceOrder;
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
            $this->setError('Erro ao salvar ordem de serviço no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'error_message'    => $e->getMessage(),
                'sql'        => $e->getSql(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'error_message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;
        }
    }
}
