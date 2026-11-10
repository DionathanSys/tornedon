@php
    $items = $this->data['items'] ?? [];
    $editable = ($this->data['source_type'] ?? 'new') === 'new';
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $total = 0;
@endphp
<div class="space-y-4">
    @if ($editable)
        <div class="flex flex-wrap gap-3">{{ $this->addItemAction }} {{ $this->catalogAction }}</div>
    @else
        <p class="text-sm text-gray-500">Os itens e valores são mantidos no documento de origem. Confira-os antes de finalizar.</p>
    @endif
    <div class="bpo-sale-items-scroll overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="bpo-sale-items-table w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="px-5 py-4 text-left whitespace-nowrap">Produto / serviço</th>
                    <th scope="col" class="px-5 py-4 text-right whitespace-nowrap">Quantidade</th>
                    <th scope="col" class="px-5 py-4 text-right whitespace-nowrap">Preço unitário</th>
                    <th scope="col" class="px-5 py-4 text-right whitespace-nowrap">Desconto</th>
                    <th scope="col" class="px-5 py-4 text-right whitespace-nowrap">Valor líquido</th>
                    @if ($editable)<th scope="col" class="bpo-sale-item-actions-cell px-5 py-4">Ações</th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($items as $key => $item)
                    @php
                        $amount = round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2) - (float) ($item['discount_amount'] ?? 0);
                        $total += $amount;
                    @endphp
                    <tr wire:key="sale-item-{{ $key }}">
                        <td class="px-5 py-3 align-middle font-medium">{{ $item['label'] ?? 'Item da venda' }}</td>
                        <td class="px-5 py-3 align-middle text-right">{{ number_format((float) ($item['quantity'] ?? 0), 3, ',', '.') }}</td>
                        <td class="px-5 py-3 align-middle text-right whitespace-nowrap">{{ $money($item['unit_price'] ?? 0) }}</td>
                        <td class="px-5 py-3 align-middle text-right whitespace-nowrap">{{ $money($item['discount_amount'] ?? 0) }}</td>
                        <td class="px-5 py-3 align-middle text-right whitespace-nowrap font-semibold">{{ $money($amount) }}</td>
                        @if ($editable)
                            <td class="bpo-sale-item-actions-cell px-5 py-3 align-middle whitespace-nowrap">
                                <div class="bpo-sale-item-actions">
                                    {{ ($this->editItemAction)(['key' => $key]) }}
                                    <x-filament::icon-button icon="heroicon-o-trash" color="danger" label="Remover item" tooltip="Remover item" wire:click="removeItem('{{ $key }}')" wire:confirm="Remover este item da venda?" />
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $editable ? 6 : 5 }}" class="p-6 text-center text-gray-500">Escolha a origem ou adicione o primeiro item da venda.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50 dark:bg-gray-800">
                <tr><th scope="row" colspan="4" class="px-5 py-3 text-right">Total dos itens</th><td class="px-5 py-3 text-right whitespace-nowrap font-semibold">{{ $money($total) }}</td>@if ($editable)<td></td>@endif</tr>
                @if ((float) ($this->data['travel_amount'] ?? 0))
                    <tr><th scope="row" colspan="4" class="px-5 py-3 text-right">Deslocamento da OS</th><td class="px-5 py-3 text-right">{{ $money($this->data['travel_amount']) }}</td>@if ($editable)<td></td>@endif</tr>
                    <tr><th scope="row" colspan="4" class="px-5 py-3 text-right">Total da venda</th><td class="px-5 py-3 text-right font-semibold">{{ $money($total + $this->data['travel_amount']) }}</td>@if ($editable)<td></td>@endif</tr>
                @endif
            </tfoot>
        </table>
    </div>
    @error('data.items')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
</div>
