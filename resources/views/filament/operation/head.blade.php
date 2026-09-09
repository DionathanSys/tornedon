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

    .fi-main {
        padding-bottom: 0;
    }
</style>
