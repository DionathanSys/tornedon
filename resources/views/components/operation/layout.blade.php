<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $livewire->getTitle() }} · Tornedon Operação</title>
    @include('filament.operation.head')
    @livewireStyles
    <script data-navigate-once>
        (() => {
            const preference = window.matchMedia('(prefers-color-scheme: dark)');
            const apply = () => {
                const theme = localStorage.getItem('theme') || 'system';
                const dark = theme === 'dark' || (theme === 'system' && preference.matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                if (document.body) document.body.dataset.theme = dark ? 'dark' : 'light';
            };
            window.addEventListener('theme-changed', event => {
                localStorage.setItem('theme', event.detail);
                apply();
            });
            preference.addEventListener('change', apply);
            document.addEventListener('livewire:navigated', apply);
            document.addEventListener('DOMContentLoaded', apply);
            apply();
        })();
    </script>
</head>
<body class="operation-ui bg-base-200 text-base-content antialiased">
    <main class="operation-main mx-auto min-h-dvh max-w-6xl px-4 py-5 sm:px-6">
        {{ $slot }}
    </main>
    @include('filament.operation.body-end')
    @livewireScripts
</body>
</html>
