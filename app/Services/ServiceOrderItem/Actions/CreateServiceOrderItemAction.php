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

class CreateServiceOrderItemAction
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
        private int $createdBy,
        private ?ServiceDiscountService $serviceDiscountService = null,
    ) {
        $this->serviceDiscountService = $serviceDiscountService ?? app(ServiceDiscountService::class);
    }

    /**
     * Cria um novo item de ordem de servico.
     *
     * @param  array  $data
     */
    public function execute(array $data): ?ServiceOrderItem
    {
        if (! isset($data['service_order_id']) || ! self::canModifyItems($data['service_order_id'])) {
            $this->setError('Nao e permitido adicionar itens a esta ordem de servico.');
            return null;
        }

        try {
            $requestedPayload = AuditLog::payload($data, self::AUDIT_FIELDS);
            $discountOrigin = (! empty($data['service_id']) && $this->shouldApplyAutomaticDiscount($data))
                ? 'automatic_or_default'
                : 'manual_or_explicit';

            Log::debug('CreateServiceOrderItemAction: criando item de ordem de servico', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $data['service_order_id'],
                'user_id' => $this->createdBy,
                'requested_payload' => $requestedPayload,
                'discount_origin' => $discountOrigin,
            ]);

            $data = $this->applyAutomaticServiceDiscount($data);
            $validated = ServiceOrderItemValidator::validateCreate($data);
            $validated = $this->normalizeServiceDiscount($validated);

            $priceError = $this->serviceDiscountService->validateEffectiveUnitPrice(
                service: (int) $validated['service_id'],
                quantity: (float) ($validated['quantity'] ?? 0),
                unitPrice: (float) ($validated['unit_price'] ?? 0),
                discountAmount: (float) ($validated['discount_amount'] ?? 0),
            );

            if ($priceError) {
                $this->setError($priceError);
                return null;
            }

            $validated['created_by'] = $this->createdBy;

            $serviceOrderItem = ServiceOrderItem::create($validated);

            Log::info('CreateServiceOrderItemAction: item de ordem de servico criado', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_order_item_id' => $serviceOrderItem->id,
                'service_order_id' => $serviceOrderItem->service_order_id,
                'user_id' => $this->createdBy,
                'requested_payload' => $requestedPayload,
                'validated_payload' => AuditLog::payload($validated, self::AUDIT_FIELDS),
                'discount_origin' => $discountOrigin,
                'pricing' => $this->buildPricingAudit($validated),
                'item_snapshot' => AuditLog::snapshot($serviceOrderItem, self::AUDIT_FIELDS),
            ]);

            $this->setSuccess();
            return $serviceOrderItem;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'requested_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao criar item da ordem de servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'requested_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item da ordem de servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'requested_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        }
    }

    private function applyAutomaticServiceDiscount(array $data): array
    {
        if (empty($data['service_id']) || empty($data['service_order_id']) || ! $this->shouldApplyAutomaticDiscount($data)) {
            return $data;
        }

        $serviceOrder = ServiceOrder::query()
            ->select(['id', 'company_id', 'customer_id'])
            ->find($data['service_order_id']);

        if (! $serviceOrder) {
            return $data;
        }

        $discount = $this->serviceDiscountService->resolveAutomaticDiscount(
            companyId: (int) $serviceOrder->company_id,
            customerId: (int) $serviceOrder->customer_id,
            service: (int) $data['service_id'],
            quantity: (float) ($data['quantity'] ?? 1),
            unitPrice: (float) ($data['unit_price'] ?? 0),
        );

        $data['discount_percentage'] = $discount['discount_percentage'];
        $data['discount_amount'] = $discount['discount_amount'];

        return $data;
    }

    private function normalizeServiceDiscount(array $data): array
    {
        $discount = $this->serviceDiscountService->buildDiscountPayload(
            service: (int) $data['service_id'],
            quantity: (float) ($data['quantity'] ?? 0),
            unitPrice: (float) ($data['unit_price'] ?? 0),
            discountPercentage: array_key_exists('discount_percentage', $data) ? (float) $data['discount_percentage'] : null,
            discountAmount: array_key_exists('discount_amount', $data) ? (float) $data['discount_amount'] : null,
            clampToMinSalePrice: false,
        );

        $data['discount_percentage'] = $discount['discount_percentage'];
        $data['discount_amount'] = $discount['discount_amount'];

        return $data;
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
