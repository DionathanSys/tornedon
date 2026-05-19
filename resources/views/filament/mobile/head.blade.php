@include('pwa.meta')

<meta
    name="viewport"
    content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover"
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
</style>
