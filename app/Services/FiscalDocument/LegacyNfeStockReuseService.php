<?php

namespace App\Services\FiscalDocument;

use App\Enum\StockMovement\Type;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalStockEffect;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;
use App\Services\Product\ProductUnitConversionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class LegacyNfeStockReuseService
{
    public function movements(FiscalDocument $document, FiscalDocumentItem $item, RequisitionItem $source): Builder
    {
        return StockMovement::withoutGlobalScope(RoleRecordScope::class)->where('company_id', $document->company_id)
            ->where(function ($query) use ($source, $item): void {
                $query->where(fn ($query) => $query->where('source_type', 'requisition_item')->where('source_id', $source->id))
                    ->orWhere(fn ($query) => $query->where('source_type', 'requisition')->where('source_id', $source->requisition_id)->where('product_id', $source->product_id))
                    ->orWhere(fn ($query) => $query->where('source_type', 'fiscal_document_item')->where('source_id', $item->id));
            });
    }

    /** Called under the invoice/document/source locks, both during recovery and before authorization. */
    public function validate(FiscalDocument $document, FiscalDocumentItem $item, RequisitionItem $source, int $movementId, ?float $quantity = null, ?string $baseUnit = null): StockMovement
    {
        $fail = function (string $reason) use ($item): never {
            throw ValidationException::withMessages(['stock' => "Item #{$item->id}: baixa antiga não pode ser reaproveitada. {$reason}"]);
        };
        $product = Product::where('company_id', $document->company_id)->find($item->product_id);
        $requisition = $source->requisition;
        if (! $product || (int) $requisition->company_id !== (int) $document->company_id
            || (int) $requisition->invoice_id !== (int) $document->invoice_id
            || (int) $source->product_id !== (int) $item->product_id
            || (string) $source->unit_of_measure !== (string) $item->unit_of_measure
            || abs((float) $source->quantity - (float) $item->quantity) > 0.00001
            || $requisition->stock_control_enabled === false
            || ! ($source->stock_control_snapshot ?? $product->has_stock_control)) {
            $fail('Origem, produto, quantidade, unidade ou política de estoque incompatível.');
        }

        // A second physical movement (including a return/adjustment) makes automatic adoption ambiguous.
        $movements = $this->movements($document, $item, $source)
            ->whereNotIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])
            ->orderBy('id')->lockForUpdate()->get();
        if ($movements->count() !== 1 || (int) $movements->sole()->id !== $movementId) {
            $fail('É necessária uma única saída rastreada, sem outras movimentações físicas na mesma origem/produto.');
        }
        $movement = $movements->sole();
        $stock = ProductStock::where('company_id', $document->company_id)->where('product_id', $product->id)->first();
        if ($quantity === null || $baseUnit === null) {
            $baseUnit = $product->unit->value;
            $quantity = $source->conversion_factor_snapshot
                ? round((float) $item->quantity * (float) $source->conversion_factor_snapshot, 8)
                : app(ProductUnitConversionService::class)->convertToBase($product, (string) $item->unit_of_measure, (float) $item->quantity)->baseQuantity;
        }
        if (! $stock || ! $stock->is_active || $movement->type !== Type::EXIT
            || (int) $movement->product_id !== (int) $product->id
            || (int) $movement->product_stock_id !== (int) $stock->id
            || $movement->base_unit !== $baseUnit || $baseUnit !== $product->unit->value
            || $movement->operational_unit !== (string) $item->unit_of_measure
            || $movement->base_quantity === null || $movement->operational_quantity === null
            || (float) $movement->base_quantity <= 0 || (float) $movement->conversion_factor_snapshot <= 0
            || abs((float) $movement->base_quantity - $quantity) > 0.00001
            || abs((float) $movement->quantity - $quantity) > 0.00001
            || abs((float) $movement->operational_quantity - (float) $item->quantity) > 0.00001
            || abs((float) $movement->operational_quantity * (float) $movement->conversion_factor_snapshot - $quantity) > 0.00001) {
            $fail('A saída deve ter estoque ativo, produto, unidades, quantidades e conversão idênticos aos da nota.');
        }
        if (FiscalStockEffect::where('company_id', $document->company_id)
            ->where('fiscal_document_item_id', '!=', $item->id)
            ->where(fn ($query) => $query->where('stock_movement_id', $movementId)->orWhere('requisition_item_id', $source->id))
            ->lockForUpdate()->exists()) {
            $fail('O movimento ou a origem já está vinculado a outro efeito fiscal.');
        }

        return $movement;
    }
}
