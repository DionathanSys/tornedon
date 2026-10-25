<!DOCTYPE html>
<html lang="pt-BR" class="fi bpo-document">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.translation-meta')
    <title>{{ $livewire->getTitle() }} · Tornedon BPO</title>
    @filamentStyles
    {{ filament()->getTheme()->getHtml() }}
    @livewireStyles
    @stack('styles')
</head>
<body class="fi-body fi-panel-bpo bpo-shell">
    @php
        $user = \Filament\Facades\Filament::auth()->user();
        $company = \Filament\Facades\Filament::getTenant();
        $companies = app(\App\Services\Bpo\BpoAccessService::class)->companies($user);
        $links = app(\App\Services\Bpo\BpoNavigation::class)->links($user, $company);
        $portfolioUrl = route('filament.bpo.portfolio');
        $otherPanels = [];
        foreach (['admin' => 'Gestão completa', 'operation' => 'Operação', 'mobile' => 'Mobile'] as $panelId => $panelLabel) {
            $otherPanel = \Filament\Facades\Filament::getPanel($panelId);
            $otherCompany = $user->getTenants($otherPanel)->first();
            if ($otherCompany) {
                $otherPanels[] = ['label' => $panelLabel, 'url' => $otherPanel->getUrl($otherCompany)];
            }
        }
    @endphp
    <a class="bpo-skip-link" href="#bpo-main">Ir para o conteúdo</a>
    <aside class="bpo-sidebar" aria-label="Navegação BPO">
        <a class="bpo-brand" href="{{ $portfolioUrl }}"><span class="bpo-brand-mark"><x-filament::icon icon="heroicon-o-chart-pie" /></span><span>tornedon<strong>BPO FINANCEIRO</strong></span></a>
        <div class="bpo-sidebar-label">ÁREA DE TRABALHO</div>
        <a href="{{ $portfolioUrl }}" class="bpo-nav-link {{ ! $company ? 'is-active' : '' }}" @if (! $company) aria-current="page" @endif><x-filament::icon icon="heroicon-o-squares-plus" /><span>Minha carteira</span><small>{{ $companies->count() }}</small></a>
        @if ($company)
            <div class="bpo-current-company"><span>EMPRESA EM OPERAÇÃO</span><strong>{{ $company->name }}</strong><small>{{ $company->document_number ?: 'Cadastro empresarial' }}</small></div>
            <nav aria-label="Operação da empresa">@foreach ($links as $link)<a href="{{ $link['url'] }}" class="bpo-nav-link {{ request()->url() === $link['url'] || ($link['key'] !== 'overview' && str_starts_with(request()->url(), $link['url'].'/')) ? 'is-active' : '' }}"><x-filament::icon :icon="$link['icon']" /><span>{{ $link['label'] }}</span></a>@endforeach</nav>
        @else
            <div class="bpo-sidebar-note"><x-filament::icon icon="heroicon-o-cursor-arrow-rays" /><strong>Uma empresa de cada vez.<br>Uma carteira sob controle.</strong><p>Abra uma empresa para acessar seus lançamentos, documentos e conciliações.</p></div>
        @endif
        <div class="bpo-sidebar-bottom"><span class="bpo-live-dot"></span><span>Acessos por atendimento autorizado</span></div>
    </aside>
    <div class="bpo-canvas">
        <header class="bpo-topbar">
            <div class="bpo-breadcrumb"><a href="{{ $portfolioUrl }}">Minha carteira</a>@if ($company)<span>/</span><strong>{{ $company->name }}</strong>@else<span>/</span><strong>Visão da operação</strong>@endif</div>
            <div class="bpo-topbar-actions">
                <label class="bpo-company-switch"><x-filament::icon icon="heroicon-o-building-office-2" /><span class="bpo-sr-only">Trocar empresa em operação</span><select aria-label="Trocar empresa em operação" onchange="if (this.value) window.location.assign(this.value)"><option value="{{ $portfolioUrl }}" @selected(! $company)>Selecionar empresa</option>@foreach ($companies as $option)<option value="{{ \App\Filament\Bpo\Pages\CompanyOverview::getUrl(panel: 'bpo', tenant: $option) }}" @selected($company?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>
                <details class="bpo-user-menu"><summary><span class="bpo-user-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span><span>{{ $user->name }}<small>Área BPO</small></span><x-filament::icon icon="heroicon-o-chevron-down" /></summary><div><p>{{ $user->email }}</p>@foreach ($otherPanels as $other)<a href="{{ $other['url'] }}">Acessar {{ $other['label'] }}</a>@endforeach<form method="POST" action="{{ \Filament\Facades\Filament::getPanel('bpo')->getLogoutUrl() }}">@csrf<button type="submit">Sair da conta</button></form></div></details>
            </div>
        </header>
        <main id="bpo-main" class="bpo-main">{{ $slot }}</main>
        <footer class="bpo-shell-footer"><span>Tornedon · Área de trabalho BPO</span><span>Dados separados por empresa. Operação conforme suas permissões.</span></footer>
    </div>
    @livewire(\Filament\Livewire\Notifications::class)
    @filamentScripts(withCore: true)
    @livewireScripts
    @stack('scripts')
</body>
</html>
