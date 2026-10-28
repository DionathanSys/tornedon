<x-filament-panels::page>
    @php
        $workflow = $this->workflowData();
        $entry = $workflow['entry'];
        $payable = $workflow['payable'];
        $hr = app(\App\Services\Hr\HrService::class);
    @endphp

    <style>
        .hr-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .hr-summary article { border: 1px solid rgba(128,128,128,.22); border-radius: .75rem; padding: 1rem 1.25rem; }
        .hr-summary small, .hr-workflow-note { display: block; font-size: .8rem; opacity: .7; }
        .hr-summary strong { display: block; font-size: 1.35rem; margin-top: .35rem; }
        .hr-workflow-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem; }
        .hr-workflow-scroll { overflow-x: auto; }
        .hr-workflow-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .hr-workflow-table th, .hr-workflow-table td { text-align: left; padding: .85rem; border-bottom: 1px solid rgba(128,128,128,.18); white-space: nowrap; }
        .hr-workflow-table th { font-weight: 600; opacity: .75; }
        .hr-workflow-table .hr-row-actions { display: flex; flex-wrap: wrap; gap: .75rem; }
        .hr-workflow-empty { padding: 1rem 0; opacity: .7; font-size: .875rem; }
    </style>

    <div class="hr-summary">
        <article><small>Competência / natureza</small><strong>{{ $entry->competence_date->format('m/Y') }}</strong><span>{{ \App\Enum\Hr\PaymentNature::options()[$entry->nature] }}</span></article>
        <article><small>Líquido da competência</small><strong>R$ {{ number_format($entry->net_amount, 2, ',', '.') }}</strong></article>
        <article><small>Total efetivamente pago</small><strong>R$ {{ number_format($workflow['paid'], 2, ',', '.') }}</strong></article>
        <article><small>Saldo a pagar</small><strong>R$ {{ number_format($workflow['balance'], 2, ',', '.') }}</strong><span>{{ ['draft' => 'Rascunho', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado'][$entry->status] }}</span></article>
    </div>

    <p><strong>Beneficiário:</strong> {{ $entry->snapshot['person_name'] ?? $entry->person?->companyPartner?->partner?->name ?? '—' }}</p>

    @if ($entry->person && \App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource::canView($entry->person))
        <div class="hr-workflow-toolbar">
            <x-filament::button color="gray" tag="a" :href="\App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource::getUrl('view', ['record' => $entry->person])">Ver cadastro do funcionário/sócio</x-filament::button>
        </div>
    @endif

    @if ($entry->status !== 'draft')
        <p class="hr-workflow-note">Os dados da competência estão preservados. Pagamentos, recibos e assinaturas são gerenciados abaixo.</p>
    @endif

    {{ $this->content }}

    <x-filament::section heading="Conta a pagar e pagamentos" description="Confirme, registre a baixa e acompanhe a obrigação financeira sem sair deste lançamento.">
        @if ($payable)
            <div class="hr-workflow-toolbar">
                <x-filament::badge color="gray">Conta a pagar #{{ $payable->id }}</x-filament::badge>
                <x-filament::badge>{{ $payable->status->description() }}</x-filament::badge>
                {{ $this->registerPaymentAction }}
            </div>
            <div class="hr-workflow-scroll">
                <table class="hr-workflow-table">
                    <thead><tr><th>Parcela</th><th>Competência</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Saldo</th></tr></thead>
                    <tbody>@foreach ($workflow['installments'] as $installment)
                        <tr><td>{{ $installment->sequence_number }}</td><td>{{ $installment->competence_date?->format('m/Y') }}</td><td>{{ $installment->due_date->format('d/m/Y') }}</td><td>R$ {{ number_format($installment->due_amount, 2, ',', '.') }}</td><td>R$ {{ number_format($installment->paid_amount, 2, ',', '.') }}</td><td>R$ {{ number_format($installment->balance_amount, 2, ',', '.') }}</td></tr>
                    @endforeach</tbody>
                </table>
            </div>
        @else
            <p class="hr-workflow-empty">{{ $entry->status === 'draft' ? 'Salve os valores e confirme o lançamento para gerar a conta a pagar.' : 'Este lançamento não possui conta a pagar. Lançamentos com líquido zero não geram nova obrigação.' }}</p>
        @endif

        @if ($workflow['payments']->isNotEmpty())
            <div class="hr-workflow-scroll" style="margin-top: 1.5rem">
                <table class="hr-workflow-table">
                    <thead><tr><th>Pagamento</th><th>Data</th><th>Conta financeira</th><th>Valor pago</th><th>Ações</th></tr></thead>
                    <tbody>@foreach ($workflow['payments'] as $payment)
                        <tr><td>#{{ $payment->id }}</td><td>{{ $payment->payment_date->format('d/m/Y') }}</td><td>{{ $payment->financialAccount?->name ?? '—' }}</td><td>R$ {{ number_format($payment->amount, 2, ',', '.') }}</td>
                            <td><div class="hr-row-actions">
                                @if ($this->hrAllowed('issue') && $entry->status === 'confirmed')
                                    <x-filament::button size="sm" color="gray" wire:click="mountAction('issueReceipt', { payment_id: {{ $payment->id }} })">Emitir recibo</x-filament::button>
                                @endif
                                @if ($this->canPerformFinancialAction('reverse'))
                                    <x-filament::button size="sm" color="danger" wire:click="mountAction('reversePayment', { payment_id: {{ $payment->id }} })">Estornar</x-filament::button>
                                @endif
                            </div></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Recibos, PDF e assinatura" description="Emita o comprovante da baixa e colete a assinatura do beneficiário. Sem assinatura eletrônica, o PDF possui espaço para data e assinatura manual.">
        <div class="hr-workflow-toolbar">{{ $this->issueReceiptAction }}</div>
        @if ($workflow['receipts']->isEmpty())
            <p class="hr-workflow-empty">Nenhum recibo disponível. Registre o pagamento e emita seu recibo nesta página.</p>
        @else
            <div class="hr-workflow-scroll">
                <table class="hr-workflow-table">
                    <thead><tr><th>Recibo</th><th>Baixa / data</th><th>Valor</th><th>Situação</th><th>Assinatura</th><th>Ações</th></tr></thead>
                    <tbody>@foreach ($workflow['receipts'] as $receipt)
                        @php $valid = $hr->receiptIsValid($receipt); @endphp
                        <tr><td>#{{ $receipt->id }}</td><td>#{{ $receipt->payment_id }} — {{ $receipt->snapshot['payment_date'] }}</td><td>R$ {{ number_format($receipt->snapshot['payment_amount'], 2, ',', '.') }}</td>
                            <td><x-filament::badge :color="$valid ? 'success' : 'danger'">{{ $valid ? 'Válido' : 'Invalidado' }}</x-filament::badge></td>
                            <td>{{ $receipt->signature ? 'Assinado em '.$receipt->signature->signed_at->format('d/m/Y H:i') : 'Pendente / manual' }}</td>
                            <td><div class="hr-row-actions">
                                @if ($valid && $this->hrAllowed('export', $receipt))
                                    <x-filament::button size="sm" color="gray" tag="a" :href="$this->receiptUrl($receipt)" target="_blank">Abrir PDF</x-filament::button>
                                @endif
                                @if ($valid && ! $receipt->signature && $this->hrAllowed('sign', $receipt))
                                    <x-filament::button size="sm" wire:click="mountAction('signReceipt', { receipt_id: {{ $receipt->id }} })">Coletar assinatura</x-filament::button>
                                @endif
                            </div></td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if (! empty($entry->advance_deductions))
        <x-filament::section heading="Adiantamentos vinculados">
            @foreach ($entry->advance_deductions as $advance)
                @php $source = \App\Models\HrEntry::query()->where('company_id', $entry->company_id)->find($advance['entry_id']); @endphp
                <div class="hr-workflow-toolbar">
                    <span>Adiantamento RH #{{ $advance['entry_id'] }} — abatimento de R$ {{ number_format($advance['amount'], 2, ',', '.') }}</span>
                    @if ($source)
                        <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Clusters\Hr\Resources\HrEntries\HrEntryResource::getUrl('edit', ['record' => $source])">Abrir adiantamento</x-filament::button>
                    @endif
                </div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
