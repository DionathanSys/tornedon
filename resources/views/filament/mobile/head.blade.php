@include('pwa.meta')

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
</style>

@if (request()->is('shop/production-requests*') || request()->is('shop/*/production-requests*'))
    <style>
        .fi-main {
            background: #f8fafc;
        }

        .fi-main-ctn {
            padding-inline: 1rem;
        }

        .fi-resource-list-records-page .fi-ta {
            gap: 1rem;
        }

        .fi-resource-list-records-page .fi-input-wrp,
        .fi-resource-list-records-page .fi-select-input,
        .fi-resource-list-records-page .fi-ta-search-field {
            min-height: 3rem;
            border-radius: 1rem;
            background: #fff;
        }

        .fi-resource-list-records-page .fi-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
            overflow-x: visible;
            border-bottom: 0;
        }

        .fi-resource-list-records-page .fi-tabs-item {
            justify-content: center;
            min-height: 4rem;
            border-radius: 0.95rem;
            background: #e2e8f0;
            color: #334155;
            font-size: 0.76rem;
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
        }

        .fi-resource-list-records-page .fi-tabs-item[aria-selected='true'] {
            background: #111827;
            color: #fff;
        }

        .fi-resource-list-records-page .fi-tabs-item .fi-badge {
            min-width: 2rem;
            justify-content: center;
        }

        .fi-resource-list-records-page .fi-ta-content,
        .fi-resource-list-records-page .fi-ta-table {
            background: transparent;
            box-shadow: none;
        }

        .fi-resource-list-records-page .fi-ta-record,
        .fi-resource-list-records-page .fi-ta-row {
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 16px 40px -34px rgba(15, 23, 42, 0.18);
        }

        .fi-resource-list-records-page .fi-ta-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
            width: 100%;
        }

        .fi-resource-list-records-page .fi-ta-actions .fi-btn {
            width: 100%;
            justify-content: center;
            border-radius: 0.85rem;
        }

        .fi-resource-list-records-page .fi-ta-actions > :first-child {
            grid-column: 1 / -1;
        }

        .fi-modal-close-overlay {
            background: rgba(15, 23, 42, 0.56);
        }

        .fi-modal-window {
            max-width: 720px;
            border-radius: 1.35rem;
        }

    </style>
@endif
