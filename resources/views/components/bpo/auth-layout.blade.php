<!DOCTYPE html>
<html lang="pt-BR" class="fi bpo-document">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Acessar BPO · Tornedon</title>@filamentStyles {{ filament()->getTheme()->getHtml() }} @livewireStyles</head>
<body class="fi-body fi-panel-bpo bpo-auth">
    <aside class="bpo-auth-story"><a class="bpo-brand" href="{{ url('/') }}"><span class="bpo-brand-mark"><x-filament::icon icon="heroicon-o-chart-pie" /></span><span>tornedon<strong>BPO FINANCEIRO</strong></span></a><div><span class="bpo-eyebrow">SUA OPERAÇÃO, EM UMA ÚNICA VISÃO</span><h1>Mais clareza para<br>cuidar de cada empresa.</h1><p>Organize o financeiro da carteira, acompanhe pendências e mantenha o contexto de cada atendimento.</p><div class="bpo-auth-points"><span><x-filament::icon icon="heroicon-o-check-circle" /> Carteira de empresas autorizadas</span><span><x-filament::icon icon="heroicon-o-check-circle" /> Financeiro e conciliação integrados</span><span><x-filament::icon icon="heroicon-o-check-circle" /> Papéis e acessos por empresa</span></div></div><small>Tornedon · Área de trabalho para BPOs e auxiliares</small></aside>
    <main class="bpo-auth-main"><div class="bpo-auth-card">{{ $slot }}</div><p>Utilize o mesmo e-mail e senha da sua conta Tornedon.</p></main>
    @livewire(\Filament\Livewire\Notifications::class) @filamentScripts(withCore: true) @livewireScripts
</body>
</html>
