<x-operation.bottom-sheet wire:model="showProductModal" :title="$editingProductId ? 'Editar produto' : 'Adicionar produto'">
    <x-mary-form wire:submit="saveProduct" no-separator>
        <x-operation.search-select label="Produto em estoque" wire:model.live="productData.product_stock_id" :options="$products" option-sub-label="availability" search-function="searchProducts" placeholder="Buscar nome ou código" debounce="300ms" />
        <p class="text-sm text-base-content/60">{{ $this->productHint }}</p>
        <div class="grid grid-cols-2 gap-4">
            <x-mary-select label="Unidade" wire:model.live="productData.unit_of_measure" :options="$this->productUnits" placeholder="Selecione" />
            <x-mary-input label="Quantidade" wire:model.blur="productData.quantity" inputmode="decimal" />
            <x-mary-input label="Preço unitário" wire:model.blur="productData.unit_price" prefix="R$" inputmode="decimal" />
            <x-mary-input label="Desconto (%)" wire:model.blur="productData.discount_percentage" suffix="%" inputmode="decimal" />
            <x-mary-input label="Desconto (R$)" wire:model.blur="productData.discount_amount" prefix="R$" inputmode="decimal" />
        </div>
        <x-operation.stat title="Total do produto" :value="$this->productTotal" class="bg-base-200" />
        <x-mary-textarea label="Observações" wire:model="productData.observations" rows="2" />
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showProductModal = false" />
            <x-mary-button label="Salvar produto" class="btn-primary" type="submit" spinner="saveProduct" />
        </x-slot:actions>
    </x-mary-form>
</x-operation.bottom-sheet>
