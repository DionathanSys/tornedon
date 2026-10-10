@include('pwa.meta', [
    'appName' => $appName ?? 'Tornedon Operação',
    'manifest' => $manifest ?? 'manifest-operation.webmanifest',
])

<meta
    name="viewport"
    content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content"
>

<style>
    .fi-modal > .fi-modal-close-overlay,
    .fi-modal > .fi-modal-window-ctn {
        z-index: 60;
    }

    @media (display-mode: standalone) {
        .fi-modal,
        .fi-modal-close-overlay {
            padding-top: calc(env(safe-area-inset-top) + 0.75rem);
            padding-bottom: calc(env(safe-area-inset-bottom) + 0.75rem);
        }

        .fi-modal-window {
            max-height: calc(100dvh - env(safe-area-inset-top) - env(safe-area-inset-bottom) - 1.5rem);
        }
    }

    @supports (-webkit-touch-callout: none) {
        @media (display-mode: standalone) {
            .fi-modal-window {
                max-height: calc(100svh - env(safe-area-inset-top) - env(safe-area-inset-bottom) - 1.5rem);
            }
        }
    }

    html {
        scroll-padding-bottom: 45dvh;
    }

    .fi-modal-content,
    .fi-main {
        scroll-padding-bottom: 45dvh;
    }

    .fi-main,
    .fi-modal-content {
        overscroll-behavior: contain;
    }

    .fi-form-actions,
    .fi-ac-modal-footer {
        gap: 0.5rem;
    }

    @media (max-width: 640px) {
        .fi-modal-window {
            max-height: min(92dvh, calc(100dvh - 1rem));
        }

        .fi-modal-content {
            padding-bottom: max(1rem, env(safe-area-inset-bottom));
        }
    }

    .op-shell {
        --op-nav-height: 4.5rem;
        min-height: 100dvh;
        padding-bottom: calc(var(--op-nav-height) + env(safe-area-inset-bottom) + 0.5rem);
        background: #f8fafc;
    }

    .fi-main.fi-main {
        padding-bottom: calc(var(--op-bottom-space, calc(5.5rem + env(safe-area-inset-bottom))) + 1.5rem);
    }

    .op-fab.op-fab {
        position: fixed;
        right: max(1.25rem, env(safe-area-inset-right));
        bottom: calc(4.5rem + env(safe-area-inset-bottom) + 1rem);
        z-index: 49;
        width: 3.5rem;
        height: 3.5rem;
        border-radius: 50%;
        background: #18181b;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    }

    .op-fab.op-fab .fi-icon { width: 1.5rem; height: 1.5rem; color: inherit; }
    html.dark .op-fab.op-fab { background: #e4e4e7; color: #18181b; }

    .op-record-actions .fi-btn {
        display: flex;
        width: 100%;
        height: 100%;
        min-width: 0;
        min-height: 3rem;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.2rem;
        border: 0;
        border-radius: 0.95rem;
        padding: 0.55rem 0.25rem;
        background: transparent;
        color: #64748b;
        box-shadow: none;
        outline-offset: 2px;
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 1;
    }

    .op-record-actions .fi-btn .fi-icon { width: 1.25rem; height: 1.25rem; color: inherit; }
    .op-record-actions.op-record-actions--1 { grid-template-columns: minmax(0, 1fr); }
    .op-record-actions.op-record-actions--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .op-record-actions.op-record-actions--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .op-record-actions .fi-dropdown,
    .op-record-actions .fi-dropdown-trigger { width: 100%; height: 100%; }
    .op-record-actions .fi-btn-label { font-size: inherit; font-weight: inherit; }
    .op-record-actions .fi-btn:hover { background: #f1f5f9; color: #18181b; }
    .op-record-actions .op-record-action--primary { background: #18181b; color: #fff; }
    html.dark .op-record-actions .fi-btn { color: #a1a1aa; }
    html.dark .op-record-actions .fi-btn:hover { background: #27272a; color: #e4e4e7; }
    html.dark .op-record-actions .op-record-action--primary { background: #e4e4e7; color: #18181b; }

    @media (min-width: 768px) {
        .op-fab.op-fab { bottom: 6.5rem; }
    }
</style>

@include('filament.operation.theme')
@include('filament.operation.modals.styles')
