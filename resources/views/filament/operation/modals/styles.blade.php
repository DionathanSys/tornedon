<style>
    .op-create-order-modal.fi-modal-window {
        border-radius: 1.65rem;
        background: #fff;
        box-shadow: 0 24px 80px rgba(15, 23, 42, 0.25);
    }

    .op-create-order-modal .fi-modal-header {
        padding: 1.6rem 3rem 1.4rem 1.5rem;
        border-radius: 1.65rem 1.65rem 0 0;
        background: linear-gradient(135deg, #18181b, #334155);
    }

    .op-create-order-heading { display: flex; align-items: center; gap: 0.9rem; }
    .op-create-order-heading__icon {
        display: flex;
        width: 3rem;
        height: 3rem;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
    }
    .op-create-order-heading__icon .fi-icon { width: 1.4rem; height: 1.4rem; }
    .op-create-order-heading__text { display: grid; gap: 0.3rem; }
    .op-create-order-heading__eyebrow {
        color: #cbd5e1;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        line-height: 1.2;
        text-transform: uppercase;
    }
    .op-create-order-heading__title { color: #fff; font-size: 1.2rem; font-weight: 800; line-height: 1.3; }
    .op-create-order-modal .fi-modal-description { margin-top: 1rem; color: #cbd5e1; font-size: 0.78rem; }
    .op-create-order-modal .fi-modal-close-btn { color: #cbd5e1; }
    .op-create-order-modal .fi-modal-close-btn .fi-icon { color: inherit; }
    .op-create-order-modal .fi-modal-close-btn:hover { color: #fff; }

    .op-create-order-modal .fi-modal-content { gap: 1.25rem; padding: 1.5rem; }
    .op-create-order-modal .fi-fo-field-wrp-label { font-size: 0.85rem; font-weight: 750; color: #0f172a; }
    .op-create-order-modal .fi-input-wrp {
        min-height: 3.25rem;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        background: #f8fafc;
        box-shadow: none;
    }
    .op-create-order-modal .fi-input-wrp:focus-within { border-color: #64748b; background: #fff; }

    .op-create-order-hint {
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        border-radius: 0.9rem;
        padding: 0.85rem;
        background: #f1f5f9;
        color: #64748b;
        font-size: 0.75rem;
        line-height: 1.5;
    }
    .op-create-order-hint .fi-icon { width: 1.15rem; height: 1.15rem; flex-shrink: 0; margin-top: 0.1rem; }
    .op-create-order-hint p { margin: 0; }

    .op-create-order-modal .fi-modal-footer {
        padding: 0 1.5rem 1.5rem;
    }
    .op-create-order-modal .fi-modal-footer-actions {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 0.65rem;
        width: 100%;
    }
    .op-create-order-modal .fi-modal-footer .fi-btn {
        width: 100%;
        min-height: 3rem;
        border-radius: 1rem;
        font-size: 0.82rem;
        font-weight: 750;
        box-shadow: none;
    }
    .op-create-order-modal .fi-modal-footer .fi-btn.fi-color-primary { order: 2; background: #18181b; color: #fff; }
    .op-create-order-modal .fi-modal-footer .fi-btn.fi-color-gray { order: 1; background: #f1f5f9; color: #475569; }

    html.dark .op-create-order-modal.fi-modal-window { background: #18181b; }
    html.dark .op-create-order-modal .fi-fo-field-wrp-label { color: #f4f4f5; }
    html.dark .op-create-order-modal .fi-input-wrp { border-color: #3f3f46; background: #27272a; }
    html.dark .op-create-order-modal .fi-input-wrp:focus-within { border-color: #a1a1aa; }
    html.dark .op-create-order-hint { background: #27272a; color: #a1a1aa; }
    html.dark .op-create-order-modal .fi-modal-footer .fi-btn.fi-color-primary { background: #e4e4e7; color: #18181b; }
    html.dark .op-create-order-modal .fi-modal-footer .fi-btn.fi-color-gray { background: #27272a; color: #d4d4d8; }
</style>
