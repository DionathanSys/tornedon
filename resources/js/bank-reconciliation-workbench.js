(function (config) {
    return {
        state: config.state,
        bank: config.bank,
        expenseOptions: config.expenseOptions ?? { categories: {}, partners: {}, cost_centers: {}, result_centers: {} },
        results: [],
        search: '',
        page: 1,
        hasMore: false,
        loading: false,
        submitting: false,
        bankExpanded: false,
        error: '',
        request: 0,
        suggestions: [],
        bundles: [],
        suggestionsLoading: false,
        suggestionsError: '',
        suggestionsWarning: '',
        suggestionsRequest: 0,
        suggestionsRejected: false,
        rejectingSuggestions: false,
        feedbackError: '',

        init() {
            this.state ??= { type: 'cash_movement', items: [], exception_reason: '' };
            if (this.bank.valid) {
                this.load();
                this.loadSuggestions();
            }
        },
        get items() { return this.state?.items ?? []; },
        get isMovement() { return this.state.type === 'cash_movement'; },
        get isRetained() { return this.state.type === 'retained_receipt'; },
        entryKey(item) { return this.isRetained ? `${item.id}:${item.statement_line_id}` : item.id; },
        itemCents(item) { return this.isRetained ? item.gross_cents : Math.round(Number(item.amount || 0) * 100); },
        expenseTotal(item) { return (item.expenses ?? []).reduce((sum, expense) => sum + (expense.amount_cents ?? 0), 0); },
        get retainedTotal() { return this.items.reduce((sum, item) => sum + this.expenseTotal(item), 0); },
        get grossTotal() { return this.items.reduce((sum, item) => sum + this.itemCents(item), 0); },
        get selectedTotal() {
            return this.grossTotal - (this.isRetained ? this.retainedTotal : 0);
        },
        get difference() { return this.bank.total_cents - this.selectedTotal; },
        get needsReason() { return this.isMovement && this.items.some(item => item.date_exception); },
        get validAmounts() {
            return this.items.every(item => {
                const amount = this.itemCents(item);
                if (this.isRetained) {
                    return Number.isSafeInteger(amount) && amount > this.expenseTotal(item) && amount <= this.adjustedBalance(item)
                        && ['interest_amount', 'fine_amount', 'discount_amount'].every(field => Number.isSafeInteger(item[field + '_cents']) && item[field + '_cents'] >= 0)
                        && this.bank.lines.some(line => line.id === Number(item.statement_line_id))
                        && (item.expenses ?? []).every(expense => Number.isSafeInteger(expense.amount_cents) && expense.amount_cents > 0
                            && expense.financial_category_id && (expense.supplier_id || expense.manual_counterparty_name?.trim()));
                }
                const validCharges = ['interest_amount', 'fine_amount', 'discount_amount'].every(field =>
                    item[field] !== null && Number.isFinite(Number(item[field] ?? 0)) && Number(item[field] ?? 0) >= 0);
                const expected = item.adjustment_expected_cents;
                const netCharges = Math.round((Number(item.interest_amount || 0) + Number(item.fine_amount || 0) - Number(item.discount_amount || 0)) * 100);
                return validCharges && Number.isFinite(amount) && amount > 0 && amount <= this.adjustedBalance(item)
                    && (typeof expected !== 'number' || netCharges === expected);
            });
        },
        get pendingAdjustments() {
            return this.items.some(item => typeof item.adjustment_expected_cents === 'number'
                && Math.round((Number(item.interest_amount || 0) + Number(item.fine_amount || 0) - Number(item.discount_amount || 0)) * 100) !== item.adjustment_expected_cents);
        },
        get canSubmit() {
            return this.bank.valid && this.items.length > 0 && this.difference === 0
                && this.validAmounts && (!this.needsReason || Boolean(this.state.exception_reason?.trim()))
                && (!this.isRetained || (this.retainedTotal > 0 && this.linesBalanced));
        },
        get linesBalanced() {
            return this.bank.lines.every(line => this.items.filter(item => Number(item.statement_line_id) === line.id)
                .reduce((sum, item) => sum + this.itemCents(item) - this.expenseTotal(item), 0) === line.amount_cents);
        },
        get status() {
            if (!this.bank.valid) return 'Selecione lançamentos pendentes da mesma conta e direção.';
            if (!this.items.length) return 'Selecione os registros do sistema que correspondem ao extrato.';
            if (this.isRetained && !this.validAmounts) return 'Confira o bruto e as despesas: valor positivo, categoria e favorecido são obrigatórios.';
            if (this.isRetained && this.retainedTotal === 0) return 'Informe a natureza e a categoria de ao menos uma despesa retida.';
            if (this.isRetained && this.difference === 0 && !this.linesBalanced) return 'Atribua os recebimentos e despesas para fechar cada depósito individualmente.';
            if (this.pendingAdjustments) return 'Defina juros, multa ou desconto para explicar a diferença da sugestão de ajuste.';
            if (!this.validAmounts) return 'Confira os ajustes e o valor da baixa: não use valores negativos nem ultrapasse o saldo ajustado.';
            if (this.difference > 0) return 'Faltam ' + this.money(this.difference) + ' para fechar. Adicione outro registro ou ajuste os valores.';
            if (this.difference < 0) return 'A seleção excedeu o extrato em ' + this.money(-this.difference) + '. Remova um registro ou ajuste a baixa.';
            if (this.needsReason && !this.state.exception_reason?.trim()) return 'Os valores fecharam. Explique a diferença de datas para continuar.';
            return 'Tudo certo! Os valores do banco e do sistema estão iguais.';
        },
        money(cents) {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100);
        },
        decimal(amount) {
            return Number(amount).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        adjustedBalance(item) {
            if (this.isMovement) return item.amount_cents;
            if (this.isRetained) return item.amount_cents + (item.interest_amount_cents ?? 0) + (item.fine_amount_cents ?? 0) - (item.discount_amount_cents ?? 0);
            return item.amount_cents + Math.round(Number(item.interest_amount || 0) * 100)
                + Math.round(Number(item.fine_amount || 0) * 100) - Math.round(Number(item.discount_amount || 0) * 100);
        },
        remainingBalance(item) {
            return this.adjustedBalance(item) - this.itemCents(item);
        },
        hasAdjustments(item) {
            return ['interest_amount', 'fine_amount', 'discount_amount'].some(field => Number(item[field] || 0) !== 0);
        },
        isSelected(id) { return this.items.some(item => item.id === id); },
        changeType(type) {
            if (this.state.type === type) return;
            this.state = { ...this.state, type, items: [], exception_reason: '', idempotency_key: type === 'retained_receipt' ? (globalThis.crypto?.randomUUID?.() ?? config.idempotencyKey) : null };
            this.search = '';
            this.load();
        },
        isSuggestionSelected(record) {
            return (this.state.type === record.type || (this.isRetained && record.type === 'receivable')) && this.isSelected(record.id);
        },
        canSelectSuggestion(record) {
            if (this.isRetained) return record.type === 'receivable';
            return !this.items.length || this.state.type === record.type;
        },
        selectSuggestion(record) {
            if (!this.canSelectSuggestion(record)) return;
            if (this.state.type !== record.type && !this.isRetained) this.changeType(record.type);
            this.toggle(record);
        },
        selectBundle(bundle) {
            if (this.items.length) return;
            this.changeType(bundle.type);
            this.state = { ...this.state, type: bundle.type, exception_reason: '', items: bundle.items.map(item => ({
                ...item, amount: item.suggested_amount, amount_manual: false,
                interest_amount: 0, fine_amount: 0, discount_amount: 0, notes: '',
                open_adjustments: bundle.kind === 'adjustment',
            })) };
        },
        async loadSuggestions() {
            if (!config.loadSuggestions || !this.bank.valid) return;
            const request = ++this.suggestionsRequest;
            this.suggestionsLoading = true;
            this.suggestionsError = '';
            this.suggestionsWarning = '';
            this.suggestionsRejected = false;
            this.feedbackError = '';
            this.state = { ...this.state, evaluation_token: null };
            this.suggestions = [];
            this.bundles = [];
            try {
                const response = await config.loadSuggestions();
                if (request !== this.suggestionsRequest) return;
                this.suggestions = response.records;
                this.bundles = response.bundles ?? [];
                this.state = { ...this.state, evaluation_token: response.evaluation_token ?? null };
                if (response.failed_lines > 0) {
                    this.suggestionsWarning = 'Não foi possível consultar as sugestões de ' + response.failed_lines
                        + ' lançamento(s). Atualize para tentar novamente.';
                }
                if (response.advanced_truncated) this.suggestionsWarning += ' A busca de composições foi limitada aos candidatos mais relevantes. Use a busca manual para conferir outras opções.';
                if (response.advanced_error) this.suggestionsWarning += ' Não foi possível consultar as composições. Atualize para tentar novamente.';
            } catch (error) {
                if (request !== this.suggestionsRequest) return;
                this.suggestionsError = 'Não foi possível carregar as sugestões. Você pode tentar novamente ou usar a busca abaixo.';
            } finally {
                if (request === this.suggestionsRequest) this.suggestionsLoading = false;
            }
        },
        async rejectSuggestions() {
            if (!this.state.evaluation_token || !config.rejectSuggestions || this.suggestionsRejected || this.rejectingSuggestions) return;
            this.rejectingSuggestions = true;
            this.feedbackError = '';
            try {
                await config.rejectSuggestions(this.state.evaluation_token);
                this.suggestionsRejected = true;
            } catch (error) {
                this.feedbackError = 'Não foi possível registrar a avaliação. Atualize as sugestões e tente novamente.';
            } finally {
                this.rejectingSuggestions = false;
            }
        },
        toggle(record) {
            if (this.isSelected(record.id)) {
                this.state = { ...this.state, items: this.items.filter(item => item.id !== record.id) };
                return;
            }
            const amount = this.isMovement || this.isRetained ? record.amount_cents
                : Math.min(record.amount_cents, this.difference > 0 ? this.difference : record.amount_cents);
            this.state = { ...this.state, items: [...this.items, {
                ...record, amount: amount / 100, amount_manual: false,
                interest_amount: 0, fine_amount: 0, discount_amount: 0, notes: '',
                ...(this.isRetained ? { gross_cents: amount, statement_line_id: this.bank.lines[0]?.id,
                    interest_amount_cents: 0, fine_amount_cents: 0, discount_amount_cents: 0, expenses: [] } : {}),
            }] };
        },
        remove(id) {
            this.state = { ...this.state, items: this.items.filter(item => this.entryKey(item) !== id) };
        },
        parseCents(value) {
            let text = String(value).trim().replace(/R\$|\s/g, '');
            if (text.includes(',')) text = text.replace(/\./g, '').replace(',', '.');
            const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(text);
            if (!match) return null;
            const result = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
            return Number.isSafeInteger(result) && result <= 99999999999999 ? result : null;
        },
        decimalCents(cents) {
            if (!Number.isSafeInteger(cents)) return '';
            return `${Math.trunc(cents / 100)},${String(Math.abs(cents % 100)).padStart(2, '0')}`;
        },
        updateReceipt(id, changes) {
            this.state = { ...this.state, items: this.items.map(item => this.entryKey(item) === id ? { ...item, ...changes } : item) };
        },
        assignLine(id, value) {
            const item = this.items.find(item => this.entryKey(item) === id);
            if (this.items.some(other => other !== item && other.id === item.id && Number(other.statement_line_id) === Number(value))) return;
            this.updateReceipt(id, { statement_line_id: Number(value) });
        },
        splitReceipt(item) {
            const line = this.bank.lines.find(line => !this.items.some(other => other.id === item.id && Number(other.statement_line_id) === line.id));
            if (line) this.state = { ...this.state, items: [...this.items, { ...item, statement_line_id: line.id, expenses: [], gross_cents: 0, amount: 0,
                interest_amount_cents: 0, fine_amount_cents: 0, discount_amount_cents: 0 }] };
        },
        addExpense(item) {
            this.updateReceipt(this.entryKey(item), { expenses: [...(item.expenses ?? []), { amount_cents: 0, financial_category_id: '', supplier_id: '', manual_counterparty_name: '', competence_date: '', cost_center_id: '', result_center_id: '', description: '' }] });
        },
        updateExpense(item, index, field, value) {
            if (field === 'amount_cents') value = this.parseCents(value);
            this.updateReceipt(this.entryKey(item), { expenses: item.expenses.map((expense, i) => i === index ? { ...expense, [field]: value } : expense) });
        },
        removeExpense(item, index) {
            this.updateReceipt(this.entryKey(item), { expenses: item.expenses.filter((_, i) => i !== index) });
        },
        parseMoney(value) {
            let normalized = String(value).trim().replace(/R\$|\s/g, '');
            if (normalized.includes(',')) normalized = normalized.replace(/\./g, '').replace(',', '.');
            return /^\d+(\.\d{1,2})?$/.test(normalized) ? Number(normalized) : null;
        },
        updateAmount(id, value) {
            if (this.isRetained) {
                this.updateReceipt(id, { gross_cents: this.parseCents(value), amount_manual: true });
                return;
            }
            const amount = this.parseMoney(value) ?? 0;
            this.state = { ...this.state, items: this.items.map(item => item.id === id ? { ...item, amount, amount_manual: true } : item) };
        },
        updateCharge(id, field, value) {
            if (this.isRetained) {
                this.updateReceipt(id, { [field + '_cents']: String(value).trim() === '' ? 0 : this.parseCents(value) });
                return;
            }
            if (this.isMovement || !['interest_amount', 'fine_amount', 'discount_amount'].includes(field)) return;
            const item = this.items.find(item => item.id === id);
            const available = this.difference + Math.round(Number(item.amount || 0) * 100);
            const updated = { ...item, [field]: String(value).trim() === '' ? 0 : this.parseMoney(value) };
            if (!updated.amount_manual) {
                updated.amount = Math.max(0, Math.min(this.adjustedBalance(updated), available)) / 100;
            }
            this.state = { ...this.state, items: this.items.map(item => item.id === id ? updated : item) };
        },
        fillRemaining(id) {
            if (this.isRetained) {
                const item = this.items.find(item => this.entryKey(item) === id);
                const bankLine = this.bank.lines.find(line => line.id === Number(item.statement_line_id));
                const others = this.items.filter(other => this.entryKey(other) !== id && Number(other.statement_line_id) === bankLine.id)
                    .reduce((sum, other) => sum + this.itemCents(other) - this.expenseTotal(other), 0);
                this.updateReceipt(id, { gross_cents: Math.max(0, Math.min(this.adjustedBalance(item), bankLine.amount_cents - others + this.expenseTotal(item))) });
                return;
            }
            const item = this.items.find(item => item.id === id);
            const available = this.difference + Math.round(Number(item.amount || 0) * 100);
            const amount = Math.max(0, Math.min(this.adjustedBalance(item), available));
            this.state = { ...this.state, items: this.items.map(item => item.id === id ? { ...item, amount: amount / 100, amount_manual: false } : item) };
        },
        async load(more = false) {
            const request = ++this.request;
            const page = more ? this.page + 1 : 1;
            this.loading = true;
            this.error = '';
            if (!more) this.results = [];
            try {
                const response = await config.searchRecords(this.isRetained ? 'receivable' : this.state.type, this.search, page);
                if (request !== this.request) return;
                this.results = more ? [...this.results, ...response.records] : response.records;
                this.hasMore = response.has_more;
                this.page = page;
            } catch (error) {
                if (request !== this.request) return;
                this.error = 'Não foi possível buscar os registros. Tente novamente.';
            } finally {
                if (request === this.request) this.loading = false;
            }
        },
        async submit() {
            if (!this.canSubmit || this.submitting) return;
            this.submitting = true;
            try { await config.submit(); }
            finally { this.submitting = false; }
        },
    };
})
