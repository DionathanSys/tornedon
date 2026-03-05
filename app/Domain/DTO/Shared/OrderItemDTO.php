<?php

namespace App\Domain\DTO\Shared;

use App\Enum\Product\Unit;
use App\Models\ProductionOrderItem;
use App\Models\QuoteItem;
use App\Models\RequisitionItem;
use App\Models\ServiceOrderItem;

/**
 * DTO compartilhado para itens de pedidos.
 *
 * Cobre os campos comuns e específicos de:
 *   - quote_items
 *   - requisition_items
 *   - service_order_items
 *   - production_order_items
 */
class OrderItemDTO
{
    public function __construct(
        /* ——— Chaves de relacionamento (contexto) ——— */
        public readonly ?int $quoteId = null,
        public readonly ?int $requisitionId = null,
        public readonly ?int $serviceOrderId = null,
        public readonly ?int $productionOrderId = null,

        /* ——— Referência ao item de origem ——— */
        public readonly ?int $quoteItemId = null,          // ProductionOrderItem

        /* ——— Produto / Serviço ——— */
        public readonly ?int $productId = null,
        public readonly ?int $productStockId = null,       // QuoteItem (Requisição via orçamento)
        public readonly ?int $serviceId = null,

        /* ——— Identificação ——— */
        public readonly ?string $description = null,
        public readonly ?string $unitOfMeasure = null,
        public readonly int $sequence = 0,

        /* ——— Quantidades ——— */
        public readonly float $quantity = 0,
        public readonly float $quantityProduced = 0,       // ProductionOrderItem
        public readonly float $quantityApproved = 0,       // ProductionOrderItem
        public readonly float $quantityRejected = 0,       // ProductionOrderItem

        /* ——— Valores ——— */
        public readonly float $unitPrice = 0,
        public readonly float $discountPercentage = 0,
        public readonly float $discountAmount = 0,

        /* ——— Custos internos (QuoteItem) ——— */
        public readonly float $materialCost = 0,
        public readonly float $laborCost = 0,
        public readonly float $estimatedProductionHours = 0,
        public readonly float $actualProductionHours = 0,  // ProductionOrderItem

        /* ——— Comissão (RequisitionItem) ——— */
        public readonly float $commissionPercentage = 0,

        /* ——— Metadados adicionais ——— */
        public readonly ?array $technicalSpecifications = null, // QuoteItem / ProductionOrderItem
        public readonly ?string $productionNotes = null,   // ProductionOrderItem
        public readonly ?string $qcNotes = null,           // ProductionOrderItem
        public readonly ?string $observations = null,      // RequisitionItem / ServiceOrderItem
        public readonly ?array $additionalInfo = null,

        /* ——— Controle de estoque (RequisitionItem) ——— */
        public readonly bool $stockConsumed = false,
        public readonly ?\DateTimeInterface $stockConsumedAt = null,
    ) {}

    /* ======================================================
     |  Factories de array genérico
     |=====================================================*/

    /**
     * Cria o DTO a partir de um array (ex: dados de formulário Filament).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            quoteId:                   $data['quote_id']                    ?? null,
            requisitionId:             $data['requisition_id']              ?? null,
            serviceOrderId:            $data['service_order_id']            ?? null,
            productionOrderId:         $data['production_order_id']         ?? null,
            quoteItemId:               $data['quote_item_id']               ?? null,
            productId:                 $data['product_id']                  ?? null,
            productStockId:            $data['product_stock_id']            ?? null,
            serviceId:                 $data['service_id']                  ?? null,
            description:               $data['description']                 ?? null,
            unitOfMeasure:             $data['unit_of_measure']             ?? null,
            sequence:                  (int)   ($data['sequence']            ?? 0),
            quantity:                  (float) ($data['quantity']            ?? 0),
            quantityProduced:          (float) ($data['quantity_produced']   ?? 0),
            quantityApproved:          (float) ($data['quantity_approved']   ?? 0),
            quantityRejected:          (float) ($data['quantity_rejected']   ?? 0),
            unitPrice:                 (float) ($data['unit_price']          ?? 0),
            discountPercentage:        (float) ($data['discount_percentage'] ?? 0),
            discountAmount:            (float) ($data['discount_amount']     ?? 0),
            materialCost:              (float) ($data['material_cost']       ?? 0),
            laborCost:                 (float) ($data['labor_cost']          ?? 0),
            estimatedProductionHours:  (float) ($data['estimated_production_hours'] ?? 0),
            actualProductionHours:     (float) ($data['actual_production_hours']    ?? 0),
            commissionPercentage:      (float) ($data['commission_percentage'] ?? 0),
            technicalSpecifications:   $data['technical_specifications']     ?? null,
            productionNotes:           $data['production_notes']             ?? null,
            qcNotes:                   $data['qc_notes']                     ?? null,
            observations:              $data['observations']                 ?? null,
            additionalInfo:            $data['additional_info']              ?? null,
            stockConsumed:             (bool)  ($data['stock_consumed']      ?? false),
            stockConsumedAt:           isset($data['stock_consumed_at'])
                                           ? new \DateTime($data['stock_consumed_at'])
                                           : null,
        );
    }

    /* ======================================================
     |  Factories a partir de Models
     |=====================================================*/

    public static function fromQuoteItem(QuoteItem $item): self
    {
        return new self(
            quoteId:                  $item->quote_id,
            productId:                $item->product_id,
            productStockId:           $item->product_stock_id ?? null,
            serviceId:                $item->service_id,
            description:              $item->description,
            unitOfMeasure:            $item->unit_of_measure,
            sequence:                 (int) ($item->sequence ?? 0),
            quantity:                 (float) $item->quantity,
            unitPrice:                (float) $item->unit_price,
            discountPercentage:       (float) $item->discount_percentage,
            discountAmount:           (float) $item->discount_amount,
            materialCost:             (float) ($item->material_cost ?? 0),
            laborCost:                (float) ($item->labor_cost ?? 0),
            estimatedProductionHours: (float) ($item->estimated_production_hours ?? 0),
            technicalSpecifications:  $item->technical_specifications,
            additionalInfo:           $item->additional_info,
        );
    }

    public static function fromRequisitionItem(RequisitionItem $item): self
    {
        return new self(
            requisitionId:        $item->requisition_id,
            productId:            $item->product_id,
            unitOfMeasure:        $item->unit_of_measure,
            quantity:             (float) $item->quantity,
            unitPrice:            (float) $item->unit_price,
            discountPercentage:   (float) $item->discount_percentage,
            discountAmount:       (float) $item->discount_amount,
            commissionPercentage: (float) ($item->commission_percentage ?? 0),
            observations:         $item->observations ?? null,
            additionalInfo:       $item->additional_info,
            stockConsumed:        (bool) $item->stock_consumed,
            stockConsumedAt:      $item->stock_consumed_at?->toDateTime(),
        );
    }

    public static function fromServiceOrderItem(ServiceOrderItem $item): self
    {
        return new self(
            serviceOrderId:     $item->service_order_id,
            serviceId:          $item->service_id,
            unitOfMeasure:      Unit::UN->value, // Força UN para serviços
            quantity:           (float) $item->quantity,
            unitPrice:          (float) $item->unit_price,
            discountPercentage: (float) $item->discount_percentage,
            discountAmount:     (float) $item->discount_amount,
            observations:       $item->observations ?? null,
            additionalInfo:     $item->additional_info,
        );
    }

    public static function fromProductionOrderItem(ProductionOrderItem $item): self
    {
        return new self(
            productionOrderId:        $item->production_order_id,
            quoteItemId:              $item->quote_item_id,
            productId:                $item->product_id,
            description:              $item->description,
            unitOfMeasure:            $item->unit_of_measure,
            sequence:                 (int) ($item->sequence ?? 0),
            quantity:                 (float) $item->quantity,
            quantityProduced:         (float) ($item->quantity_produced ?? 0),
            quantityApproved:         (float) ($item->quantity_approved ?? 0),
            quantityRejected:         (float) ($item->quantity_rejected ?? 0),
            actualProductionHours:    (float) ($item->actual_production_hours ?? 0),
            technicalSpecifications:  $item->technical_specifications,
            productionNotes:          $item->production_notes ?? null,
            qcNotes:                  $item->qc_notes ?? null,
            additionalInfo:           $item->additional_info,
        );
    }

    /* ======================================================
     |  Serialização
     |=====================================================*/

    /**
     * Retorna apenas os campos relevantes para quote_items.
     */
    public function toQuoteItemArray(): array
    {
        return array_filter([
            'quote_id'                   => $this->quoteId,
            'product_id'                 => $this->productId,
            'product_stock_id'           => $this->productStockId,
            'service_id'                 => $this->serviceId,
            'description'                => $this->description,
            'unit_of_measure'            => $this->unitOfMeasure,
            'sequence'                   => $this->sequence,
            'quantity'                   => $this->quantity,
            'unit_price'                 => $this->unitPrice,
            'discount_percentage'        => $this->discountPercentage,
            'discount_amount'            => $this->discountAmount,
            'material_cost'              => $this->materialCost,
            'labor_cost'                 => $this->laborCost,
            'estimated_production_hours' => $this->estimatedProductionHours,
            'technical_specifications'   => $this->technicalSpecifications,
            'additional_info'            => $this->additionalInfo,
        ], fn ($v) => $v !== null);
    }

    /**
     * Retorna apenas os campos relevantes para requisition_items.
     */
    public function toRequisitionItemArray(): array
    {
        return array_filter([
            'requisition_id'        => $this->requisitionId,
            'product_id'            => $this->productId,
            'unit_of_measure'       => $this->unitOfMeasure,
            'quantity'              => $this->quantity,
            'unit_price'            => $this->unitPrice,
            'discount_percentage'   => $this->discountPercentage,
            'discount_amount'       => $this->discountAmount,
            'commission_percentage' => $this->commissionPercentage,
            'observations'          => $this->observations,
            'additional_info'       => $this->additionalInfo,
            'stock_consumed'        => $this->stockConsumed,
            'stock_consumed_at'     => $this->stockConsumedAt?->format('Y-m-d H:i:s'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Retorna apenas os campos relevantes para service_order_items.
     */
    public function toServiceOrderItemArray(): array
    {
        return array_filter([
            'service_order_id'    => $this->serviceOrderId,
            'service_id'          => $this->serviceId,
            'unit_of_measure'     => $this->unitOfMeasure,
            'quantity'            => $this->quantity,
            'unit_price'          => $this->unitPrice,
            'discount_percentage' => $this->discountPercentage,
            'discount_amount'     => $this->discountAmount,
            'observations'        => $this->observations,
            'additional_info'     => $this->additionalInfo,
        ], fn ($v) => $v !== null);
    }

    /**
     * Retorna apenas os campos relevantes para production_order_items.
     */
    public function toProductionOrderItemArray(): array
    {
        return array_filter([
            'production_order_id'     => $this->productionOrderId,
            'quote_item_id'           => $this->quoteItemId,
            'product_id'              => $this->productId,
            'description'             => $this->description,
            'unit_of_measure'         => $this->unitOfMeasure,
            'sequence'                => $this->sequence,
            'quantity'                => $this->quantity,
            'quantity_produced'       => $this->quantityProduced,
            'quantity_approved'       => $this->quantityApproved,
            'quantity_rejected'       => $this->quantityRejected,
            'actual_production_hours' => $this->actualProductionHours,
            'technical_specifications'=> $this->technicalSpecifications,
            'production_notes'        => $this->productionNotes,
            'qc_notes'                => $this->qcNotes,
            'additional_info'         => $this->additionalInfo,
        ], fn ($v) => $v !== null);
    }

    /* ======================================================
     |  Cálculos
     |=====================================================*/

    public function subtotal(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    public function totalAmount(): float
    {
        return $this->subtotal() - $this->discountAmount;
    }
}
