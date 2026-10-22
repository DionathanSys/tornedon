<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="brw"
        x-data="{{ file_get_contents(resource_path('js/bank-reconciliation-workbench.js')) }}({
            state: $wire.entangle(@js($getStatePath())),
            bank: @js($getBankSummary()),
            searchRecords: (type, search, page) => $wire.callSchemaComponentMethod(@js($getKey()), 'searchRecords', { type, search, page }),
            submit: () => $wire.callMountedAction(),
        })"
    >
        <div class="brw-layout">
            <aside class="brw-bank">
                <div class="brw-eyebrow"><x-filament::icon icon="heroicon-o-building-library" /> Extrato bancário</div>
                <h3 x-text="bank.account"></h3>
                <div class="brw-bank-total" x-text="money(bank.total_cents)"></div>
                <p class="brw-muted" x-text="bank.lines.length + (bank.lines.length === 1 ? ' lançamento selecionado' : ' lançamentos selecionados')"></p>
                <span class="brw-badge" x-text="bank.inflow ? 'Entrada no banco' : 'Saída do banco'"></span>
                <button type="button" class="brw-bank-toggle" @click="bankExpanded = !bankExpanded" :aria-expanded="bankExpanded" x-text="bankExpanded ? 'Ocultar lançamentos' : 'Ver os lançamentos do extrato'"></button>
                <div class="brw-bank-lines" :class="{ 'is-expanded': bankExpanded }">
                    <template x-for="line in bank.lines" :key="line.id">
                        <div class="brw-bank-line">
                            <div class="brw-row"><span x-text="line.date"></span><strong x-text="money(line.amount_cents)"></strong></div>
                            <p x-text="line.description"></p>
                        </div>
                    </template>
                </div>
                <div class="brw-bank-tip"><x-filament::icon icon="heroicon-o-arrow-right-circle" /><p>Selecione ao lado os registros que, juntos, correspondem a este valor.</p></div>
            </aside>

            <section class="brw-system" aria-label="Registros do sistema">
                <div class="brw-section-heading"><div><div class="brw-eyebrow">Registros do sistema</div><h3>O que corresponde a esse lançamento?</h3></div></div>
                <div class="brw-modes" role="group" aria-label="Tipo de conciliação">
                    <button type="button" class="brw-mode" :class="{ 'is-active': isMovement }" :aria-pressed="isMovement" @click="changeType('cash_movement')">
                        <x-filament::icon icon="heroicon-o-link" />
                        <span><strong>Já lançado no sistema</strong><small>Apenas vincular, sem nova baixa</small></span>
                    </button>
                    <button type="button" class="brw-mode" :class="{ 'is-active': !isMovement }" :aria-pressed="!isMovement" @click="changeType(bank.inflow ? 'receivable' : 'payable')">
                        <x-filament::icon icon="heroicon-o-document-currency-dollar" />
                        <span><strong x-text="bank.inflow ? 'Conta a receber em aberto' : 'Conta a pagar em aberto'"></strong><small x-text="bank.inflow ? 'Registrar o recebimento agora' : 'Registrar o pagamento agora'"></small></span>
                    </button>
                </div>

                <label class="brw-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" />
                    <span class="brw-sr-only">Buscar por descrição, cliente, fornecedor, número ou valor</span>
                    <input type="search" x-model="search" @input.debounce.350ms="load()" @keydown.enter.prevent="load()" placeholder="Busque por descrição, cliente, fornecedor, nº ou valor…" autocomplete="off" />
                </label>
                <div class="brw-results-heading"><span>Selecione um ou mais registros</span><span class="brw-muted">Valores mais próximos primeiro</span></div>
                <div class="brw-results" :aria-busy="loading">
                    <p class="brw-empty" x-show="loading && !results.length" role="status">Buscando registros…</p>
                    <div class="brw-empty" x-show="error" x-cloak role="alert"><p x-text="error"></p><button type="button" class="brw-text-button" @click="load()">Tentar novamente</button></div>
                    <div class="brw-empty" x-show="!loading && !error && !results.length" x-cloak>
                        <x-filament::icon icon="heroicon-o-magnifying-glass" />
                        <strong>Nenhum registro encontrado</strong>
                        <p>Tente outro nome, trecho da descrição ou valor. Confira também se a opção acima corresponde ao que você procura.</p>
                    </div>
                    <template x-for="record in results" :key="state.type + '-' + record.id">
                        <button type="button" class="brw-record" :class="{ 'is-selected': isSelected(record.id) }" role="checkbox" :aria-checked="isSelected(record.id)" @click="toggle(record)">
                            <span class="brw-check" aria-hidden="true" x-text="isSelected(record.id) ? '✓' : '+'"></span>
                            <span class="brw-record-main"><strong x-text="record.party || record.description"></strong><span x-text="record.party ? record.description : 'Registro #' + record.id"></span><small><span x-text="(isMovement ? 'Lançado em ' : 'Vence em ') + (record.date || '—')"></span> · #<span x-text="record.id"></span></small></span>
                            <span class="brw-record-value"><strong x-text="money(record.amount_cents)"></strong><small class="brw-match" x-show="record.exact_match">Mesmo valor do extrato</small><small class="brw-date-warning" x-show="record.date_exception">Data fora da margem</small></span>
                        </button>
                    </template>
                    <button type="button" class="brw-load-more" x-show="hasMore && !error" :disabled="loading" @click="load(true)" x-text="loading ? 'Carregando…' : 'Carregar mais registros'"></button>
                </div>

                <div class="brw-selection" x-show="items.length" x-cloak>
                    <div class="brw-selection-heading"><strong>Selecionados <span class="brw-count" x-text="items.length"></span></strong><button type="button" class="brw-text-button" @click="state = { ...state, items: [] }">Limpar seleção</button></div>
                    <div class="brw-selected-list">
                        <template x-for="item in items" :key="state.type + '-selected-' + item.id">
                            <div class="brw-selected-item">
                                <div class="brw-selected-title"><strong x-text="item.party || item.description"></strong><small x-text="item.description + ' · #' + item.id"></small><small class="brw-date-warning" x-show="item.date_exception">Data diferente da registrada no banco</small></div>
                                <div class="brw-edit-value">
                                    <strong x-show="isMovement" x-text="money(item.amount_cents)"></strong>
                                    <template x-if="!isMovement">
                                        <div>
                                            <label class="brw-amount"><span>R$</span><input type="text" inputmode="decimal" :aria-label="'Valor da baixa do registro ' + item.id" :value="decimal(item.amount)" @change="updateAmount(item.id, $event.target.value)" @keydown.enter.prevent="$event.target.blur()" /></label>
                                            <small class="brw-muted" x-text="'Saldo: ' + money(item.amount_cents)"></small>
                                            <button type="button" class="brw-text-button" x-show="difference !== 0" @click="fillRemaining(item.id)">Usar valor restante</button>
                                            <small class="brw-partial" x-show="item.amount > 0 && Math.round(item.amount * 100) < item.amount_cents" x-text="'Ficará em aberto: ' + money(item.amount_cents - Math.round(item.amount * 100))"></small>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" class="brw-remove" :aria-label="'Remover registro ' + item.id" @click="remove(item.id)"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                            </div>
                        </template>
                    </div>
                    <p class="brw-effect" x-text="isMovement ? 'Os movimentos existentes serão apenas vinculados. Nenhum pagamento ou recebimento será duplicado.' : 'As baixas serão registradas nas datas do extrato. Valores parciais mantêm o saldo restante em aberto.'"></p>
                </div>
                <div class="brw-reason" x-show="needsReason" x-cloak>
                    <label for="{{ $getId() }}-reason">Por que a data do sistema é diferente da data do banco?</label>
                    <p>Um dos movimentos selecionados está fora da margem de dias configurada para esta conta.</p>
                    <textarea id="{{ $getId() }}-reason" x-model="state.exception_reason" rows="2" placeholder="Ex.: recebimento lançado pelo banco alguns dias depois."></textarea>
                </div>
            </section>
        </div>

        <footer class="brw-footer">
            <div class="brw-totals" aria-live="polite">
                <div><span>No banco</span><strong x-text="money(bank.total_cents)"></strong></div>
                <div><span>No sistema</span><strong x-text="money(selectedTotal)"></strong></div>
                <div class="brw-difference" :class="{ 'is-balanced': items.length && difference === 0 }"><span>Diferença</span><strong x-text="money(difference)"></strong></div>
            </div>
            <div class="brw-footer-bottom"><p class="brw-status" :class="{ 'is-balanced': canSubmit }" x-text="status" role="status"></p><div class="brw-footer-actions"><button type="button" class="brw-cancel" :disabled="submitting" @click="$wire.unmountAction()">Cancelar</button><button type="button" class="brw-submit" :disabled="!canSubmit || submitting" @click="submit()"><x-filament::icon icon="heroicon-o-check" /><span x-text="submitting ? 'Conciliando…' : 'Confirmar conciliação'"></span></button></div></div>
        </footer>
    </div>
    @once
        @include('forms.components.bank-reconciliation-workbench-styles')
    @endonce
</x-dynamic-component>
