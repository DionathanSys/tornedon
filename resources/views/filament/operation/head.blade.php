@include('pwa.meta', [
    'appName' => $appName ?? 'Tornedon Operação',
    'manifest' => $manifest ?? 'manifest-operation.webmanifest',
])

<meta
    name="viewport"
    content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content"
>

<style>
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
        padding-bottom: calc(var(--op-nav-offset, calc(4.5rem + env(safe-area-inset-bottom))) + var(--op-actions-height, 0px) + 1.5rem);
    }

    .op-action-bar {
        position: fixed;
        right: 0;
        bottom: var(--op-nav-offset, calc(4.5rem + env(safe-area-inset-bottom)));
        left: 0;
        z-index: 49;
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem max(0.75rem, env(safe-area-inset-right)) 0.75rem max(0.75rem, env(safe-area-inset-left));
        border-top: 1px solid #e4e4e7;
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(16px);
    }

    html.dark .op-action-bar {
        border-color: #3f3f46;
        background: rgba(24, 24, 27, 0.96);
    }

    @media (min-width: 768px) {
        .op-action-bar {
            right: 1rem;
            left: 1rem;
            max-width: 48rem;
            margin-inline: auto;
            border: 1px solid #e4e4e7;
            border-radius: 1rem;
        }
    }
</style>

@include('filament.operation.theme')
