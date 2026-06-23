<?php

namespace App\Services\WarrantyClaim;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status;
use App\Enum\WarrantyClaim\SupplierDecision;
use App\Enum\WarrantyClaim\SupplierResolution;
use App\Enum\WarrantyClaim\Type;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\WarrantyClaim;
use App\Services\WarrantyClaim\Validators\WarrantyClaimValidator;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WarrantyClaimService
{
    use HandlesServiceResponse;

    public function create(array $data, int $userId): ?WarrantyClaim
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $userId): ?WarrantyClaim {
                if (blank($data['number'] ?? null)) {
                    $data['number'] = $this->generateNumber((int) $data['company_id']);
                }

                $validated = WarrantyClaimValidator::validateCreate($data);
                $validated['created_by'] = $userId;
                $validated['updated_by'] = $userId;
                $validated['advanced_replacement'] = (bool) ($validated['advanced_replacement'] ?? false);

                $claim = WarrantyClaim::query()->create($validated);

                $this->setSuccess('Garantia criada com sucesso.');

                return $claim;
            });
        } catch (ValidationException $e) {
            $this->setError('Não foi possível criar a garantia.', $e->errors());

            return null;
        } catch (\Throwable $e) {
            Log::error('WarrantyClaimService: erro ao criar garantia', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'data' => $data,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);

            $this->setError('Erro ao criar garantia.');

            return null;
        }
    }

    public function update(WarrantyClaim $claim, array $data, int $userId): ?WarrantyClaim
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($claim, $data, $userId): ?WarrantyClaim {
                $validated = WarrantyClaimValidator::validateUpdate(
                    array_merge($claim->toArray(), $data, ['company_id' => $claim->company_id]),
                    $claim->id,
                    $claim->company_id,
                );

                $validated['updated_by'] = $userId;
                $claim->fill($validated)->save();

                $this->setSuccess('Garantia atualizada com sucesso.');

                return $claim->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Não foi possível atualizar a garantia.', $e->errors());

            return null;
        } catch (\Throwable $e) {
            Log::error('WarrantyClaimService: erro ao atualizar garantia', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'warranty_claim_id' => $claim->id,
                'data' => $data,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);

            $this->setError('Erro ao atualizar garantia.');

            return null;
        }
    }

    public function openFromServiceOrder(ServiceOrder $serviceOrder, array $data, int $userId): ?WarrantyClaim
    {
        $payload = array_merge([
            'company_id' => $serviceOrder->company_id,
            'type' => Type::SERVICE_COMPANY->value,
            'status' => Status::DRAFT->value,
            'customer_id' => $serviceOrder->customer_id,
            'origin_service_order_id' => $serviceOrder->id,
            'equipment_id' => $serviceOrder->equipment_id,
            'quantity' => 1,
            'expires_at' => $serviceOrder->warranty_expires_at?->toDateString(),
            'coverage_type' => CoverageType::LABOR_AND_PARTS->value,
            'responsibility' => Responsibility::COMPANY->value,
            'supplier_decision' => SupplierDecision::PENDING->value,
            'supplier_resolution' => SupplierResolution::NONE->value,
        ], $data);

        return $this->create($payload, $userId);
    }

    public function openFromRequisition(Requisition $requisition, array $data, int $userId): ?WarrantyClaim
    {
        $requisition->loadMissing(['items.product', 'invoice.fiscalDocuments']);

        $productId = (int) ($data['product_id'] ?? 0);
        $item = $requisition->items->first(fn ($requisitionItem): bool => (int) $requisitionItem->product_id === $productId);

        if ($item === null) {
            $this->setError('Selecione um produto válido da requisição para abrir a garantia.', ['product_id' => 'Produto inválido.']);

            return null;
        }

        $payload = array_merge([
            'company_id' => $requisition->company_id,
            'type' => Type::PRODUCT_SUPPLIER->value,
            'status' => Status::DRAFT->value,
            'customer_id' => $requisition->customer_id,
            'origin_service_order_id' => $requisition->service_order_id,
            'origin_requisition_id' => $requisition->id,
            'origin_invoice_id' => $requisition->invoice_id,
            'origin_fiscal_document_id' => $requisition->invoice?->fiscalDocuments->first()?->id,
            'product_id' => $item->product_id,
            'equipment_id' => $requisition->equipment_id,
            'quantity' => (float) $item->quantity,
            'coverage_type' => CoverageType::PARTS->value,
            'responsibility' => Responsibility::SUPPLIER->value,
            'supplier_decision' => SupplierDecision::PENDING->value,
            'supplier_resolution' => SupplierResolution::NONE->value,
        ], $data);

        return $this->create($payload, $userId);
    }

    private function generateNumber(int $companyId): string
    {
        $lastNumber = WarrantyClaim::query()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->max('number');

        return str_pad((string) (((int) $lastNumber) + 1), 5, '0', STR_PAD_LEFT);
    }
}
