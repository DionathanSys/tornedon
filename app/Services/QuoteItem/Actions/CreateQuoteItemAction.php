<?php

namespace App\Services\QuoteItem\Actions;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\QuoteItem\Validators\QuoteItemValidator;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Support\Audit\AuditLog;
use App\Traits\AuthorizesQuoteItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateQuoteItemAction
{
    use HandlesActionResponse, AuthorizesQuoteItemActions;

    private const AUDIT_FIELDS = [
        'quote_id',
        'product_id',
        'service_id',
        'description',
        'quantity',
        'unit_of_measure',
        'unit_price',
        'discount_percentage',
        'discount_amount',
        'technical_specifications',
        'estimated_production_hours',
        'material_cost',
        'labor_cost',
        'sequence',
        'additional_info',
        'destination',
        'status',
    ];

    public function __construct(
        private int $createdBy,
        private ?ServiceDiscountService $serviceDiscountService = null,
    ) {
        $this->serviceDiscountService = $serviceDiscountService ?? app(ServiceDiscountService::class);
    }

    /**
     * Cria um novo item de orcamento.
     *
     * @param  array  $data
     */
    public function execute(array $data): ?QuoteItem
    {
        if (! isset($data['quote_id']) || ! self::canModifyQuoteItems($data['quote_id'])) {
            $this->setError('Nao e permitido adicionar itens a este orcamento. Apenas orcamentos em rascunho podem ter itens alterados.');
            return null;
        }

        try {
            $requestedPayload = AuditLog::payload($data, self::AUDIT_FIELDS);
            $discountOrigin = (! empty($data['service_id']) && $this->shouldApplyAutomaticDiscount($data))
                ? 'automatic_or_default'
                : 'manual_or_explicit';

            Log::debug('CreateQuoteItemAction: criando item de orcamento', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'quote_id' => $data['quote_id'],
                'user_id' => $this->createdBy,
                'requested_payload' => $requestedPayload,
                'discount_origin' => $discountOrigin,
            ]);

            $data = $this->applyAutomaticServiceDiscount($data);
            $validated = QuoteItemValidator::validateCreate($data);
            $validated = $this->normalizeServiceDiscount($validated);

            if (! empty($validated['product_id']) && isset($validated['unit_price'])) {
                $priceError = $this->validateMinSalePrice((int) $validated['product_id'], (float) $validated['unit_price']);
                if ($priceError) {
                    $this->setError($priceError);
                    return null;
                }
            }

            if (! empty($validated['service_id'])) {
                $priceError = $this->validateServicePricing(
                    (int) $validated['service_id'],
                    (float) ($validated['quantity'] ?? 0),
                    (float) ($validated['unit_price'] ?? 0),
                    (float) ($validated['discount_amount'] ?? 0),
                );

                if ($priceError) {
                    $this->setError($priceError);
                    return null;
                }
            }

            $item = QuoteItem::create($validated);

            Log::info('CreateQuoteItemAction: item de orcamento criado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'item_id' => $item->id,
                'quote_id' => $item->quote_id,
                'user_id' => $this->createdBy,
                'requested_payload' => $requestedPayload,
                'validated_payload' => AuditLog::payload($validated, self::AUDIT_FIELDS),
                'discount_origin' => $discountOrigin,
                'pricing' => $this->buildPricingAudit($validated),
                'item_snapshot' => AuditLog::snapshot($item, self::AUDIT_FIELDS),
            ]);

            $this->setSuccess();
            return $item;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'requested_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao criar item de orcamento no banco de dados');

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'requested_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item de orcamento');

            Log::error('CreateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
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
        if (empty($data['service_id']) || empty($data['quote_id']) || ! $this->shouldApplyAutomaticDiscount($data)) {
            return $data;
        }

        $quote = Quote::query()
            ->select(['id', 'company_id', 'customer_id'])
            ->find($data['quote_id']);

        if (! $quote) {
            return $data;
        }

        $discount = $this->serviceDiscountService->resolveAutomaticDiscount(
            companyId: (int) $quote->company_id,
            customerId: (int) $quote->customer_id,
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
        if (empty($data['service_id'])) {
            return $data;
        }

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
            && $data['discount_percentage'] !== null
            && $data['discount_percentage'] !== '';
        $hasAmount = array_key_exists('discount_amount', $data)
            && $data['discount_amount'] !== null
            && $data['discount_amount'] !== '';

        return ! $hasPercentage && ! $hasAmount;
    }

    /**
     * Verifica se o preco unitario respeita o preco minimo de venda do produto.
     * Retorna null se OK, ou mensagem de erro caso contrario.
     */
    private function validateMinSalePrice(int $productId, float $unitPrice): ?string
    {
        $product = Product::find($productId);

        if (! $product || ! $product->min_sale_price || $product->min_sale_price <= 0) {
            return null;
        }

        $minPrice = (float) $product->min_sale_price;

        if ($unitPrice < $minPrice) {
            return sprintf(
                'O preco unitario (R$ %s) esta abaixo do preco minimo de venda de "%s" (R$ %s).',
                number_format($unitPrice, 2, ',', '.'),
                $product->name,
                number_format($minPrice, 2, ',', '.')
            );
        }

        return null;
    }

    private function validateServicePricing(int $serviceId, float $quantity, float $unitPrice, float $discountAmount): ?string
    {
        return $this->serviceDiscountService->validateEffectiveUnitPrice(
            service: $serviceId,
            quantity: $quantity,
            unitPrice: $unitPrice,
            discountAmount: $discountAmount,
        );
    }

    private function buildPricingAudit(array $data): ?array
    {
        if (empty($data['service_id'])) {
            return null;
        }

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
