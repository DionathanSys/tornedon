<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\Audit\AuditRecorder;
use App\Services\ProductionOrder\Validators\ProductionOrderValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateProductionOrder
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?ProductionOrder
    {
        try {
            $validatedData = ProductionOrderValidator::validateCreate($data);

            $productionOrder = ProductionOrder::create([
                'company_id'        => $validatedData['company_id'],
                'customer_id'        => $validatedData['customer_id'] ?? null,
                'quote_id'          => $validatedData['quote_id'] ?? null,
                'status'            => Status::QUEUED->value,
                'priority'          => $validatedData['priority'],
                'destination_type'  => $validatedData['destination_type'],
                'observations'      => $validatedData['observations'] ?? null,
                'assigned_operator' => $validatedData['assigned_operator'] ?? null,
                'assigned_machine'  => $validatedData['assigned_machine'] ?? null,
                'created_by'        => $this->createdBy,
            ]);
            $audit = app(AuditRecorder::class);

            $audit->recordModelEvent(
                $productionOrder,
                'production_order.created',
                "Ordem de produção #{$productionOrder->production_order_number} criada",
                null,
                $audit->snapshot($productionOrder),
                $this->createdBy,
            );

            $this->setSuccess();

            return $productionOrder;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Falha de validação dos dados',
                'errors'     => $e->errors(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar ordem de produção: ' . $e->getMessage());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}
