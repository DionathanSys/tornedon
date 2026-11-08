<?php

namespace App\Filament\Operation\Concerns;

use App\Models\ProductStock;
use App\Models\ServiceOrder;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductUnitConversionService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\ServiceOrder\ServiceOrderRequisitionService;
use App\Support\OperationMoney;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait HasServiceOrderProducts
{
    public bool $showProductModal = false;

    #[Locked]
    public ?int $editingProductId = null;

    public array $productData = [];

    public array $products = [];

    private function editableProductOrder(): ServiceOrder
    {
        $order = $this->tenantOrder();
        abort_unless($order, 404);
        abort_unless($order->state()->canEdit(), 403);
        $requisition = $order->requisition;
        abort_if($requisition && ($requisition->company_id !== $order->company_id || ! $requisition->state()->canEdit()), 403);

        return $order;
    }

    private function productStocks(): Builder
    {
        return ProductStock::query()->with('product.alternativeUnitConversions')
            ->where('company_id', Filament::getTenant()?->getKey())
            ->whereHas('product', fn ($query) => $query->where('company_id', Filament::getTenant()?->getKey())->where('is_active', true)->where('is_invoiceable', true));
    }

    public function openAddProduct(): void
    {
        $this->editableProductOrder();
        $this->resetValidation();
        $this->editingProductId = null;
        $this->productData = ['product_stock_id' => null, 'unit_of_measure' => null, 'quantity' => 1, 'unit_price' => '', 'discount_percentage' => 0, 'discount_amount' => 0, 'observations' => ''];
        $this->searchProducts();
        $this->showProductModal = true;
    }

    public function openEditProduct(int $itemId): void
    {
        $order = $this->editableProductOrder();
        $item = $order->requisitionItems()->find($itemId);
        abort_unless($item, 404);
        $this->resetValidation();
        $this->editingProductId = $item->id;
        $this->productData = $item->only(['unit_of_measure', 'quantity', 'unit_price', 'discount_percentage', 'discount_amount', 'observations']);
        $this->productData['product_stock_id'] = $this->productStocks()->where('product_id', $item->product_id)->value('id');
        $this->searchProducts();
        $this->showProductModal = true;
    }

    public function searchProducts(string $search = ''): void
    {
        abort_unless(Filament::getTenant(), 403);
        $query = $this->productStocks();
        $records = (clone $query)->whereHas('product', fn ($query) => $query
            ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('product_code', 'like', "%{$search}%")))
            ->orderBy('id')->limit(30)->get();
        if ($selected = (clone $query)->find($this->productData['product_stock_id'] ?? null)) {
            $records = $records->push($selected)->unique('id');
        }
        $this->products = $records->map(fn ($stock): array => [
            'id' => $stock->id,
            'name' => "[{$stock->product->product_code}] {$stock->product->name} · Disponível: ".number_format((float) $stock->quantity_available, 3, ',', '.').' '.$stock->product->unit->value,
        ])->values()->all();
    }

    public function updatedProductData(mixed $value, ?string $key = null): void
    {
        $this->editableProductOrder();
        if ($key === 'product_stock_id') {
            $stock = $this->productStocks()->find($value);
            $this->productData['unit_of_measure'] = $stock?->product?->resolvedSaleUnitValue();
            $this->productData['unit_price'] = $stock ? (app(ProductSalePriceService::class)->resolve($stock->product, $stock) ?? 0) : '';
            $this->productData['discount_percentage'] = 0;
            $this->productData['discount_amount'] = 0;
        } elseif (in_array($key, ['quantity', 'unit_price', 'discount_percentage', 'discount_amount'], true)) {
            $subtotal = OperationMoney::amount($this->productData['quantity'] ?? 0) * OperationMoney::amount($this->productData['unit_price'] ?? 0);
            if ($key === 'discount_amount') {
                $this->productData['discount_percentage'] = $subtotal > 0 ? round(OperationMoney::amount($value) / $subtotal * 100, 2) : 0;
            } else {
                $this->productData['discount_amount'] = round($subtotal * OperationMoney::amount($this->productData['discount_percentage'] ?? 0) / 100, 2);
            }
        }
    }

    #[Computed]
    public function productUnits(): array
    {
        $stock = $this->productStocks()->find($this->productData['product_stock_id'] ?? null);

        return $stock ? array_map(fn ($unit): array => ['id' => $unit, 'name' => $unit], app(ProductUnitConversionService::class)->getAvailableUnits($stock->product)) : [];
    }

    #[Computed]
    public function productHint(): ?string
    {
        $stock = $this->productStocks()->find($this->productData['product_stock_id'] ?? null);
        if (! $stock) {
            return null;
        }
        $hint = 'Disponível: '.number_format((float) $stock->quantity_available, 3, ',', '.').' '.$stock->product->unit->value;
        $minimum = (float) $stock->product->min_sale_price;
        if ($minimum > 0) {
            $hint .= ' · Preço mínimo unitário: R$ '.number_format($minimum, 2, ',', '.');
        }
        $unit = $this->productData['unit_of_measure'] ?? null;
        $conversion = app(ProductUnitConversionService::class);
        if ($unit && $conversion->isAllowedUnit($stock->product, $unit)) {
            $result = $conversion->convertToBase($stock->product, $unit, max(0, OperationMoney::amount($this->productData['quantity'] ?? 0)));
            $hint .= ' · '.$result->displayRule.' · Consumo: '.number_format($result->baseQuantity, 3, ',', '.').' '.$result->baseUnit;
        }

        return $hint;
    }

    #[Computed]
    public function productTotal(): string
    {
        return 'R$ '.number_format(OperationMoney::amount($this->productData['quantity'] ?? 0) * OperationMoney::amount($this->productData['unit_price'] ?? 0) - OperationMoney::amount($this->productData['discount_amount'] ?? 0), 2, ',', '.');
    }

    public function saveProduct(): void
    {
        $order = $this->editableProductOrder();
        foreach (['quantity', 'unit_price', 'discount_percentage', 'discount_amount'] as $field) {
            $this->productData[$field] = OperationMoney::normalize($this->productData[$field] ?? null);
        }
        $data = $this->validate([
            'productData.product_stock_id' => ['required', 'integer', Rule::exists('product_stocks', 'id')->where('company_id', $order->company_id)->whereNull('deleted_at')],
            'productData.unit_of_measure' => ['required', Rule::in(array_column($this->productUnits(), 'id'))],
            'productData.quantity' => 'required|numeric|min:0.001',
            'productData.unit_price' => 'required|numeric|min:0',
            'productData.discount_percentage' => 'required|numeric|min:0|max:100',
            'productData.discount_amount' => 'required|numeric|min:0',
            'productData.observations' => 'nullable|string|max:1000',
        ])['productData'];
        $stock = $this->productStocks()->find($data['product_stock_id']);
        if (! $stock) {
            $this->addError('productData.product_stock_id', 'Selecione um produto ativo e disponível para venda.');

            return;
        }
        $data['product_id'] = $stock->product_id;
        unset($data['product_stock_id']);
        if ($data['discount_amount'] > round($data['quantity'] * $data['unit_price'], 2)) {
            $this->addError('productData.discount_amount', 'O desconto não pode superar o valor do produto.');

            return;
        }
        $item = $this->editingProductId ? $order->requisitionItems()->find($this->editingProductId) : null;
        abort_if($this->editingProductId && ! $item, 404);
        DB::transaction(function () use ($order, $data, $item): void {
            $linked = app(ServiceOrderRequisitionService::class);
            $requisition = $linked->getOrCreateEditable($order, (int) Auth::id());
            if (! $requisition) {
                throw ValidationException::withMessages(['productData.product_stock_id' => $linked->getMessageUser()]);
            }
            $service = app(RequisitionItemService::class);
            $result = $item ? $service->update($item, $data, (int) Auth::id()) : $service->create([...$data, 'requisition_id' => $requisition->id], (int) Auth::id());
            if (! $result || $service->hasError()) {
                throw ValidationException::withMessages(['productData.unit_price' => $service->getMessageUser()]);
            }
        });
        $this->showProductModal = false;
        $this->loadOrder(preserveForm: true);
        $this->success('Produto salvo com sucesso.');
    }

    public function deleteProduct(int $itemId): void
    {
        $order = $this->editableProductOrder();
        $item = $order->requisitionItems()->find($itemId);
        abort_unless($item, 404);
        $service = app(RequisitionItemService::class);
        if (! $service->forceDelete($item) || $service->hasError()) {
            $this->error($service->getMessageUser());

            return;
        }
        $this->loadOrder(preserveForm: true);
        $this->success('Produto excluído com sucesso.');
    }
}
