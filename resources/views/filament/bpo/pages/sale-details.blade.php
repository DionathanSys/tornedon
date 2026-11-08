<div class="space-y-6">
    <dl class="bpo-account-fields">
        <div><dt>Origem operacional</dt><dd>{{ $sale->service_order_id ? 'OS' : 'Requisição' }} #{{ $source->number }}</dd></div>
        <div><dt>Fatura</dt><dd>@if ($invoice)<x-filament::link :href="\App\Filament\Bpo\Resources\InvoiceResource::getUrl('view', ['record' => $invoice], panel: 'bpo', tenant: filament()->getTenant())">Abrir fatura #{{ $invoice->invoice_number }}</x-filament::link>@else Ainda não finalizada ou sem acesso à fatura @endif</dd></div>
        <div><dt>Total</dt><dd>R$ {{ number_format($invoice?->netValue ?? ($source->total_amount + ($linkedRequisition?->total_amount ?? 0)), 2, ',', '.') }}</dd></div>
        <div><dt>Tratamento financeiro</dt><dd>{{ ['create_receivable' => 'Gerar recebível', 'link_existing' => 'Recebível vinculado', 'fiscal_only' => 'Somente nota'][$sale->payment_settings['financial_policy'] ?? ''] ?? 'A definir' }}</dd></div>
        <div><dt>Estoque</dt><dd>{{ ($sale->template_snapshot['stock_control_enabled'] ?? false) ? 'Reservas na requisição; saída após autorização fiscal' : 'Sem movimentação de estoque' }}</dd></div>
    </dl>
    <div class="overflow-x-auto"><table class="w-full text-sm"><caption class="text-left font-semibold">Itens da venda</caption><thead><tr><th class="p-2 text-left">Item</th><th class="p-2">Quantidade</th><th class="p-2">Valor unitário</th><th class="p-2">Desconto</th><th class="p-2">Total</th></tr></thead><tbody>
        @foreach ($source->items->concat($linkedRequisition?->items ?? []) as $item)
            <tr><td class="p-2">{{ $item->service?->name ?? $item->product?->name ?? $item->description }}</td><td class="p-2">{{ number_format($item->quantity, 3, ',', '.') }}</td><td class="p-2">R$ {{ number_format($item->unit_price, 2, ',', '.') }}</td><td class="p-2">R$ {{ number_format($item->discount_amount, 2, ',', '.') }}</td><td class="p-2">R$ {{ number_format($item->total_amount, 2, ',', '.') }}</td></tr>
        @endforeach
    </tbody></table></div>
    @if ($invoice)
        <section><h3 class="font-semibold">Contas a receber</h3>
            @forelse ($invoice->accountReceivables as $title)
                @can('view', $title)<p><x-filament::link :href="\App\Filament\Bpo\Resources\AccountReceivableResource::getUrl('view', ['record' => $title], panel: 'bpo', tenant: filament()->getTenant())">Abrir recebível #{{ $title->id }} · {{ $title->description }}</x-filament::link></p>@endcan
            @empty <p>Nenhum recebível gerado para esta política financeira.</p> @endforelse
        </section>
        <section><h3 class="font-semibold">Documentos fiscais</h3>
            @foreach ($invoice->fiscalDocuments as $document)
                @can('view', $document)<p><x-filament::link :href="\App\Filament\Bpo\Pages\FiscalDocuments::getUrl(['tableFilters' => ['document_id' => ['value' => $document->id]]], panel: 'bpo', tenant: filament()->getTenant())">{{ $document->document_type->description() }} #{{ $document->document_number ?: $document->id }} · {{ ($document->isNfse() ? $document->nfse_status : $document->nfe_status)?->description() ?? 'Em preparação' }}</x-filament::link></p>@endcan
            @endforeach
        </section>
    @endif
</div>
