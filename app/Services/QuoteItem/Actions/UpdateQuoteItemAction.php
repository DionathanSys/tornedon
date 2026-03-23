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

class UpdateQuoteItemAction
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
        private int $updatedBy,
        private QuoteItem $item,
        private ?ServiceDiscountService $serviceDiscountService = null,
    ) {
        $this->serviceDiscountService = $serviceDiscountService ?? app(ServiceDiscountService::class);
    }

    /**
     * Atualiza um item de orcamento existente.
     *
     * @param  array  $data
     */
    public function execute(array $data): ?QuoteItem
    {
        if (! self::canModifyQuoteItems($this->item->quote_id)) {
            $this->setError('Nao e permitido atualizar itens deste orcamento. Apenas orcamentos em rascunho podem ter itens alterados.');
            return null;
        }

        try {
            $beforeSnapshot = AuditLog::snapshot($this->item, self::AUDIT_FIELDS);

            Log::debug('UpdateQuoteItemAction: atualizando item de orcamento', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
                'quote_id' => $this->item->quote_id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'before_snapshot' => $beforeSnapshot,
            ]);

            $validated = QuoteItemValidator::validateUpdate($data);
            $finalData = $this->buildFinalPayload($validated);

            if (! empty($finalData['product_id']) && isset($finalData['unit_price'])) {
                $priceError = $this->validateMinSalePrice((int) $finalData['product_id'], (float) $finalData['unit_price']);
                if ($priceError) {
                    $this->setError($priceError);
                    return null;
                }
            }

            if (! empty($finalData['service_id'])) {
                $priceError = $this->validateServicePricing(
                    (int) $finalData['service_id'],
                    (float) ($finalData['quantity'] ?? 0),
                    (float) ($finalData['unit_price'] ?? 0),
                    (float) ($finalData['discount_amount'] ?? 0),
                );

                if ($priceError) {
                    $this->setError($priceError);
                    return null;
                }
            }

            $this->item->update($validated);
            $this->item->refresh();

            $afterSnapshot = AuditLog::snapshot($this->item, self::AUDIT_FIELDS);

            Log::info('UpdateQuoteItemAction: item de orcamento atualizado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'item_id' => $this->item->id,
                'quote_id' => $this->item->quote_id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'applied_changes' => AuditLog::diff($beforeSnapshot, $afterSnapshot),
                'pricing' => $this->buildPricingAudit($finalData),
                'after_snapshot' => $afterSnapshot,
            ]);

            $this->setSuccess();
            return $this->item;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'item_id' => $this->item->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item de orcamento no banco de dados');

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'item_id' => $this->item->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item de orcamento');

            Log::error('UpdateQuoteItemAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'item_id' => $this->item->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        }
    }

    private function buildFinalPayload(array $validated): array
    {
        $finalData = array_merge([
            'product_id' => $this->item->product_id,
            'service_id' => $this->item->service_id,
            'quantity' => (float) $this->item->quantity,
            'unit_price' => (float) $this->item->unit_price,
            'discount_percentage' => (float) $this->item->discount_percentage,
            'discount_amount' => (float) $this->item->discount_amount,
        ], $validated);

        $serviceIdChanged = array_key_exists('service_id', $validated)
            && (int) ($validated['service_id'] ?? 0) !== (int) ($this->item->service_id ?? 0);

        if (! empty($finalData['service_id']) && $serviceIdChanged && $this->shouldApplyAutomaticDiscount($validated)) {
            $quote = Quote::query()
                ->select(['id', 'company_id', 'customer_id'])
                ->find($this->item->quote_id);

            if ($quote) {
                $discount = $this->serviceDiscountService->resolveAutomaticDiscount(
                    companyId: (int) $quote->company_id,
                    customerId: (int) $quote->customer_id,
                    service: (int) $finalData['service_id'],
                    quantity: (float) ($finalData['quantity'] ?? 0),
                    unitPrice: (float) ($finalData['unit_price'] ?? 0),
                );

                $validated['discount_percentage'] = $discount['discount_percentage'];
                $validated['discount_amount'] = $discount['discount_amount'];
                $finalData['discount_percentage'] = $discount['discount_percentage'];
                $finalData['discount_amount'] = $discount['discount_amount'];
            }
        }

        if (! empty($finalData['service_id'])) {
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

            if (array_key_exists('discount_percentage', $validated) || array_key_exists('discount_amount', $validated) || $serviceIdChanged) {
                $validated['discount_percentage'] = $discount['discount_percentage'];
                $validated['discount_amount'] = $discount['discount_amount'];
            }
        }

        return $finalData;
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
