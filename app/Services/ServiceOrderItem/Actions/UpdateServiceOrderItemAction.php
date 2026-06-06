<?php

namespace App\Services\ServiceOrderItem\Actions;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Services\ServiceOrderItem\Validators\ServiceOrderItemValidator;
use App\Support\Audit\AuditLog;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceOrderItemAction
{
    use HandlesActionResponse, AuthorizesServiceOrderItemActions;

    private const AUDIT_FIELDS = [
        'service_order_id',
        'service_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount_percentage',
        'discount_amount',
        'observations',
        'additional_info',
    ];

    public function __construct(
        private int $updatedBy,
        private ServiceOrderItem $serviceOrderItem,
        private ?ServiceDiscountService $serviceDiscountService = null,
    ) {
        $this->serviceDiscountService = $serviceDiscountService ?? app(ServiceDiscountService::class);
    }

    /**
     * Atualiza um item de ordem de servico existente.
     *
     * @param  array  $data
     */
    public function execute(array $data): ?ServiceOrderItem
    {
        if (! self::canModifyItems($this->serviceOrderItem->service_order_id)) {
            $this->setError('Nao e permitido atualizar itens desta ordem de servico.');
            return null;
        }

        try {
            $beforeSnapshot = AuditLog::snapshot($this->serviceOrderItem, self::AUDIT_FIELDS);

            Log::debug('UpdateServiceOrderItemAction: atualizando item de ordem de servico', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_item_id' => $this->serviceOrderItem->id,
                'service_order_id' => $this->serviceOrderItem->service_order_id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'before_snapshot' => $beforeSnapshot,
            ]);

            $validated = ServiceOrderItemValidator::validateUpdate($data);
            $finalData = $this->buildFinalPayload($validated);

            $priceError = $this->serviceDiscountService->validateEffectiveUnitPrice(
                service: (int) $finalData['service_id'],
                quantity: (float) ($finalData['quantity'] ?? 0),
                unitPrice: (float) ($finalData['unit_price'] ?? 0),
                discountAmount: (float) ($finalData['discount_amount'] ?? 0),
            );

            if ($priceError) {
                $this->setError($priceError);
                return null;
            }

            $validated['updated_by'] = $this->updatedBy;

            if (array_key_exists('discount_percentage', $finalData)) {
                $validated['discount_percentage'] = $finalData['discount_percentage'];
            }

            if (array_key_exists('discount_amount', $finalData)) {
                $validated['discount_amount'] = $finalData['discount_amount'];
            }

            $this->serviceOrderItem->update($validated);
            $this->serviceOrderItem->refresh();

            $afterSnapshot = AuditLog::snapshot($this->serviceOrderItem, self::AUDIT_FIELDS);

            Log::info('UpdateServiceOrderItemAction: item de ordem de servico atualizado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_item_id' => $this->serviceOrderItem->id,
                'service_order_id' => $this->serviceOrderItem->service_order_id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'applied_changes' => AuditLog::diff($beforeSnapshot, $afterSnapshot),
                'pricing' => $this->buildPricingAudit($finalData),
                'after_snapshot' => $afterSnapshot,
            ]);

            $this->setSuccess();
            return $this->serviceOrderItem;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'service_order_item_id' => $this->serviceOrderItem->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da ordem de servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'service_order_item_id' => $this->serviceOrderItem->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item da ordem de servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'service_order_item_id' => $this->serviceOrderItem->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        }
    }

    private function buildFinalPayload(array $validated): array
    {
        $finalData = array_merge([
            'service_id' => $this->serviceOrderItem->service_id,
            'quantity' => (float) $this->serviceOrderItem->quantity,
            'unit_price' => (float) $this->serviceOrderItem->unit_price,
            'discount_percentage' => (float) $this->serviceOrderItem->discount_percentage,
            'discount_amount' => (float) $this->serviceOrderItem->discount_amount,
        ], $validated);

        $serviceIdChanged = array_key_exists('service_id', $validated)
            && (int) ($validated['service_id'] ?? 0) !== (int) ($this->serviceOrderItem->service_id ?? 0);

        if ($serviceIdChanged && $this->shouldApplyAutomaticDiscount($validated)) {
            $serviceOrder = ServiceOrder::query()
                ->select(['id', 'company_id', 'customer_id'])
                ->find($this->serviceOrderItem->service_order_id);

            if ($serviceOrder) {
                $discount = $this->serviceDiscountService->resolveAutomaticDiscount(
                    companyId: (int) $serviceOrder->company_id,
                    customerId: (int) $serviceOrder->customer_id,
                    service: (int) $finalData['service_id'],
                    quantity: (float) ($finalData['quantity'] ?? 0),
                    unitPrice: (float) ($finalData['unit_price'] ?? 0),
                );

                $finalData['discount_percentage'] = $discount['discount_percentage'];
                $finalData['discount_amount'] = $discount['discount_amount'];
            }
        }

        $discount = $this->serviceDiscountService->buildDiscountPayload(
            service: (int) $finalData['service_id'],
            quantity: (float) ($finalData['quantity'] ?? 0),
            unitPrice: (float) ($finalData['unit_price'] ?? 0),
            discountPercentage: array_key_exists('discount_percentage', $finalData) ? (float) $finalData['discount_percentage'] : null,
            discountAmount: array_key_exists('discount_amount', $finalData) ? (float) $finalData['discount_amount'] : null,
            clampToMinSalePrice: false,
        );

        $finalData['discount_percentage'] = $discount['discount_percentage'];
        $finalData['discount_amount'] = $discount['discount_amount'];

        return $finalData;
    }

    private function shouldApplyAutomaticDiscount(array $data): bool
    {
        $hasPercentage = array_key_exists('discount_percentage', $data)
            && ! $this->isMissingDiscountValue($data['discount_percentage']);
        $hasAmount = array_key_exists('discount_amount', $data)
            && ! $this->isMissingDiscountValue($data['discount_amount']);

        return ! $hasPercentage && ! $hasAmount;
    }

    private function isMissingDiscountValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function buildPricingAudit(array $data): array
    {
        return $this->serviceDiscountService->buildDiscountPayload(
            service: (int) $data['service_id'],
            quantity: (float) ($data['quantity'] ?? 0),
            unitPrice: (float) ($data['unit_price'] ?? 0),
            discountPercentage: array_key_exists('discount_percentage', $data) ? (float) $data['discount_percentage'] : null,
            discountAmount: array_key_exists('discount_amount', $data) ? (float) $data['discount_amount'] : null,
            clampToMinSalePrice: false,
        );
    }
}
