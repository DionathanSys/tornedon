@include('pwa.meta', [
    'appName' => $appName ?? 'Tornedon Operação',
    'manifest' => $manifest ?? 'manifest-operation.webmanifest',
])

<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">

@vite('resources/css/operation.css')
