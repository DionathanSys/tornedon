<style>
    .financial-dashboard {
        --financial-muted: #6b7280;
        --financial-border: #e5e7eb;
        --financial-hover: #f9fafb;
        display: grid;
        gap: 1.5rem;
    }
    .dark .financial-dashboard {
        --financial-muted: #9ca3af;
        --financial-border: rgb(255 255 255 / 0.1);
        --financial-hover: rgb(255 255 255 / 0.04);
    }
    .financial-dashboard .financial-dashboard__periods {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 1rem;
    }
    .financial-dashboard__periods > button { margin: 0; }
    .financial-dashboard__period-help { margin-top: 1.5rem; color: var(--financial-muted); font-size: .875rem; line-height: 1.7; }
    .financial-dashboard__columns { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .financial-dashboard__column { display: grid; gap: 1.5rem; min-width: 0; }
    .financial-dashboard__summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1.5rem; }
    .financial-dashboard__amount { font-size: 1.75rem; font-weight: 700; line-height: 1.4; font-variant-numeric: tabular-nums; }
    .financial-dashboard__amount--danger { color: #dc2626; }
    .financial-dashboard__amount--success { color: #059669; }
    .dark .financial-dashboard__amount--danger { color: #f87171; }
    .dark .financial-dashboard__amount--success { color: #34d399; }
    .financial-dashboard__muted { color: var(--financial-muted); font-size: .875rem; line-height: 1.6; }
    .financial-dashboard__overdue { display: grid; gap: .375rem; align-content: start; }
    .financial-dashboard__overdue-amount { color: #dc2626; font-size: 1.125rem; font-weight: 600; }
    .dark .financial-dashboard__overdue-amount { color: #f87171; }
    .financial-dashboard__search-label { display: block; margin-bottom: .75rem; font-size: .875rem; font-weight: 500; }
    .financial-dashboard__list { display: grid; gap: .75rem; }
    .financial-dashboard__list > [role="listitem"] { border: 1px solid var(--financial-border); border-radius: .875rem; overflow: hidden; }
    .financial-dashboard .financial-installment { display: flex; align-items: flex-start; gap: .875rem; padding: 1.125rem; transition: background-color .15s; }
    .financial-dashboard .financial-installment:hover { background: var(--financial-hover); }
    .financial-installment > .mary-hideable:not(.financial-installment__actions) { flex: 1; min-width: 0; overflow: visible; white-space: normal; }
    .financial-installment__icon { display: flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: .75rem; }
    .financial-installment__icon svg { width: 1.25rem; height: 1.25rem; }
    .financial-installment__icon--payable { background: rgb(239 68 68 / .1); color: #dc2626; }
    .financial-installment__icon--receivable { background: rgb(16 185 129 / .1); color: #059669; }
    .dark .financial-installment__icon--payable { color: #f87171; }
    .dark .financial-installment__icon--receivable { color: #34d399; }
    .financial-dashboard .financial-installment__title { font-size: .9375rem; font-weight: 600; white-space: normal; overflow-wrap: anywhere; }
    .financial-dashboard .financial-installment__details { display: grid; gap: .5rem; margin-top: .375rem; color: var(--financial-muted); font-size: .8125rem; white-space: normal; overflow-wrap: anywhere; }
    .financial-installment__description { line-height: 1.5; }
    .financial-installment__date { display: flex; align-items: center; gap: .375rem; }
    .financial-installment__date svg { width: 1rem; height: 1rem; flex-shrink: 0; }
    .financial-installment__badges { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .125rem; }
    .financial-dashboard .financial-installment__actions { display: flex; flex-direction: column; align-items: flex-end; gap: .375rem; flex-shrink: 0; }
    .financial-installment__amount { font-size: 1rem; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .financial-installment__balance-label { color: var(--financial-muted); font-size: .75rem; }
    .financial-installment__actions a { margin-top: .5rem; font-size: .8125rem; }
    .financial-dashboard__empty { display: grid; justify-items: center; gap: .875rem; padding: 2rem 1rem; text-align: center; color: var(--financial-muted); font-size: .875rem; }
    .financial-dashboard__empty-icon { width: 2rem; height: 2rem; }
    .financial-dashboard__pagination { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--financial-border); }
    @media (min-width: 1024px) {
        .financial-dashboard__columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .financial-dashboard__columns--single { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 1279px) {
        .financial-dashboard .financial-installment { flex-wrap: wrap; }
        .financial-dashboard .financial-installment__actions { width: 100%; align-items: flex-start; padding-top: .75rem; border-top: 1px solid var(--financial-border); }
    }
</style>
