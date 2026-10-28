<x-filament-panels::page class="bpo-workbench-page">
    @php($import = $this->getRecord())
    <div class="bpo-account-top-grid">
        <section class="bpo-workbench bpo-account-summary" aria-label="Resumo do extrato">
            <header class="bpo-workbench-header">
                <div><span class="bpo-eyebrow">CONTA FINANCEIRA</span><h2>{{ $import->financialAccount?->name ?: 'Não informada' }}</h2><p>{{ $import->file_name }}</p></div>
                <span class="bpo-badge">{{ $import->status?->description() ?? 'Não informado' }}</span>
            </header>
            <dl class="bpo-account-fields">
                <div><dt>Banco</dt><dd>{{ data_get($import->metadata, 'institution_name') ?: 'Não informado' }}</dd></div>
                <div><dt>Agência</dt><dd>{{ data_get($import->metadata, 'branch_id') ?: 'Não informada' }}</dd></div>
                <div><dt>Conta no extrato</dt><dd>{{ data_get($import->metadata, 'account_id') ?: 'Não informada' }}</dd></div>
                <div><dt>Início do período</dt><dd>{{ data_get($import->metadata, 'statement_start') ? \Carbon\Carbon::parse($import->metadata['statement_start'])->format('d/m/Y') : 'Não informado' }}</dd></div>
                <div><dt>Fim do período</dt><dd>{{ data_get($import->metadata, 'statement_end') ? \Carbon\Carbon::parse($import->metadata['statement_end'])->format('d/m/Y') : 'Não informado' }}</dd></div>
                <div><dt>Linhas importadas</dt><dd>{{ $import->line_count }}</dd></div>
            </dl>
            <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-document-text" /><span>Importado em {{ $import->created_at?->format('d/m/Y H:i') }}.</span></footer>
        </section>
        <aside class="bpo-workbench" aria-label="Orientações de conciliação">
            <header class="bpo-workbench-header"><div><span class="bpo-eyebrow">CONFERÊNCIA BANCÁRIA</span><h2>Do extrato ao sistema</h2><p>Confira os movimentos antes de concluir a conciliação.</p></div><x-filament::icon icon="heroicon-o-arrows-right-left" /></header>
            <div class="bpo-account-description"><span>Como conciliar</span><p>Selecione os movimentos do extrato e utilize as ações disponíveis para relacioná-los aos registros do sistema. Confira a data, a descrição e o valor de cada lançamento.</p></div>
        </aside>
    </div>
    <section class="bpo-account-relationships" aria-label="Movimentos do extrato">
        <header class="bpo-account-relationship-heading"><div><h2>Movimentos do extrato</h2><p>Acompanhe pendências e confira os vínculos de cada lançamento bancário.</p></div><span class="bpo-badge">{{ $import->line_count }} linhas importadas</span></header>
        @livewire(\App\Filament\Bpo\Resources\BankStatementImports\LinesRelationManager::class, ['ownerRecord' => $import, 'pageClass' => $this::class], key('bank-import-lines-'.$import->id))
    </section>
</x-filament-panels::page>
