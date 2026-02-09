<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Services\ProductionOrder\Validators\ProductionOrderValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
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
            $validatedData = ProductionOrderValidator::validate($data);
            
            DB::beginTransaction();

            $productionOrderData = [
                'company_id' => $validatedData['company_id'],
                'quote_id' => $validatedData['quote_id'] ?? null,
                'partner_id' => $validatedData['partner_id'],
                'status' => Status::QUEUED->value,
                'priority' => $validatedData['priority'],
                'destination_type' => $validatedData['destination_type'],
                'observations' => $validatedData['observations'] ?? null,
                'assigned_operator' => $validatedData['assigned_operator'] ?? null,
                'assigned_machine' => $validatedData['assigned_machine'] ?? null,
                'created_by' => $this->createdBy,
            ];

            $productionOrder = ProductionOrder::create($productionOrderData);

            // Create production order items
            foreach ($validatedData['items'] as $index => $itemData) {
                ProductionOrderItem::create([
                    'production_order_id' => $productionOrder->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_of_measure' => $itemData['unit_of_measure'],
                    'technical_specifications' => $itemData['technical_specifications'] ?? null,
                    'sequence' => $index + 1,
                ]);
            }

            DB::commit();
            $this->setSuccess();
            
            return $productionOrder;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->setError('Falha de validação dos dados', $e->errors());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Falha de validação dos dados',
                'errors'     => $e->errors(),
            ]);
            
            return null;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->setError('Erro ao criar ordem de produção: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
            ]);
            
            return null;
        }
    }
}
