(function (config) {
    return {
        state: config.state,
        bank: config.bank,
        results: [],
        search: '',
        page: 1,
        hasMore: false,
        loading: false,
        submitting: false,
        bankExpanded: false,
        error: '',
        request: 0,

        init() {
            this.state ??= { type: 'cash_movement', items: [], exception_reason: '' };
            if (this.bank.valid) this.load();
        },
        get items() { return this.state?.items ?? []; },
        get isMovement() { return this.state.type === 'cash_movement'; },
        get selectedTotal() {
            return this.items.reduce((sum, item) => sum + Math.round(Number(item.amount || 0) * 100), 0);
        },
        get difference() { return this.bank.total_cents - this.selectedTotal; },
        get needsReason() { return this.isMovement && this.items.some(item => item.date_exception); },
        get validAmounts() {
            return this.items.every(item => {
                const amount = Math.round(Number(item.amount) * 100);
                const validCharges = ['interest_amount', 'fine_amount', 'discount_amount'].every(field =>
                    item[field] !== null && Number.isFinite(Number(item[field] ?? 0)) && Number(item[field] ?? 0) >= 0);
                return validCharges && Number.isFinite(amount) && amount > 0 && amount <= this.adjustedBalance(item);
            });
        },
        get canSubmit() {
            return this.bank.valid && this.items.length > 0 && this.difference === 0
                && this.validAmounts && (!this.needsReason || Boolean(this.state.exception_reason?.trim()));
        },
        get status() {
            if (!this.bank.valid) return 'Selecione lançamentos pendentes da mesma conta e direção.';
            if (!this.items.length) return 'Selecione os registros do sistema que correspondem ao extrato.';
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
            return item.amount_cents + Math.round(Number(item.interest_amount || 0) * 100)
                + Math.round(Number(item.fine_amount || 0) * 100) - Math.round(Number(item.discount_amount || 0) * 100);
        },
        remainingBalance(item) {
            return this.adjustedBalance(item) - Math.round(Number(item.amount || 0) * 100);
        },
        hasAdjustments(item) {
            return ['interest_amount', 'fine_amount', 'discount_amount'].some(field => Number(item[field] || 0) !== 0);
        },
        isSelected(id) { return this.items.some(item => item.id === id); },
        changeType(type) {
            if (this.state.type === type) return;
            this.state = { type, items: [], exception_reason: '' };
            this.search = '';
            this.load();
        },
        toggle(record) {
            if (this.isSelected(record.id)) {
                this.remove(record.id);
                return;
            }
            const amount = this.isMovement ? record.amount_cents
                : Math.min(record.amount_cents, this.difference > 0 ? this.difference : record.amount_cents);
            this.state = { ...this.state, items: [...this.items, {
                ...record, amount: amount / 100, amount_manual: false,
                interest_amount: 0, fine_amount: 0, discount_amount: 0, notes: '',
            }] };
        },
        remove(id) {
            this.state = { ...this.state, items: this.items.filter(item => item.id !== id) };
        },
        parseMoney(value) {
            let normalized = String(value).trim().replace(/R\$|\s/g, '');
            if (normalized.includes(',')) normalized = normalized.replace(/\./g, '').replace(',', '.');
            return /^\d+(\.\d{1,2})?$/.test(normalized) ? Number(normalized) : null;
        },
        updateAmount(id, value) {
            const amount = this.parseMoney(value) ?? 0;
            this.state = { ...this.state, items: this.items.map(item => item.id === id ? { ...item, amount, amount_manual: true } : item) };
        },
        updateCharge(id, field, value) {
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
                const response = await config.searchRecords(this.state.type, this.search, page);
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
