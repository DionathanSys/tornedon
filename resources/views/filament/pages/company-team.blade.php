<x-filament-panels::page>
    @php
        $team = $this->teamData();
        $manager = $this->isManager();
        $person = $team['selectedPerson'];
        $role = $team['selectedRole'];
        $tabs = ['people' => ['label' => 'Pessoas', 'icon' => 'heroicon-o-users'], 'roles' => ['label' => 'Papéis e permissões', 'icon' => 'heroicon-o-adjustments-horizontal'], 'bpo' => ['label' => 'Atendimentos BPO', 'icon' => 'heroicon-o-briefcase'], 'invitations' => ['label' => 'Convites', 'icon' => 'heroicon-o-envelope']];
    @endphp
    <div class="ctw">
        <header class="ctw-intro">
            <div><span class="ctw-eyebrow">{{ \Filament\Facades\Filament::getTenant()->name }}</span><p>Escolha uma pessoa para gerenciar seus acessos. Use os papéis para definir o que cada função pode fazer.</p></div>
            <div class="ctw-actions">{{ $this->assignAction }} {{ $this->inviteAction }}</div>
        </header>

        @if ($invitationUrl)
            <div class="ctw-notice is-success" role="status" x-data="{ copied: false, failed: false }">
                <x-filament::icon icon="heroicon-o-check-circle" />
                <div><strong>Convite criado</strong><p>Válido por 7 dias. Você também pode compartilhar o link com o destinatário.</p><a class="ctw-invitation-link" href="{{ $invitationUrl }}">{{ $invitationUrl }}</a><small x-show="failed" x-cloak>Selecione o link acima para copiá-lo manualmente.</small></div>
                <button type="button" class="ctw-outline-button" @click="navigator.clipboard?.writeText(@js($invitationUrl)).then(() => { copied = true; failed = false }).catch(() => failed = true) ?? (failed = true)" x-text="copied ? 'Link copiado' : 'Copiar link'"></button>
            </div>
        @endif

        <nav class="ctw-tabs" aria-label="Gestão de acessos">
            @foreach ($tabs as $key => $tab)
                @if ($key !== 'roles' || $manager)
                    <button type="button" wire:click="changeTab('{{ $key }}')" class="ctw-tab {{ $activeTab === $key ? 'is-active' : '' }}" aria-pressed="{{ $activeTab === $key ? 'true' : 'false' }}">
                        <x-filament::icon :icon="$tab['icon']" /><span>{{ $tab['label'] }}</span><span class="ctw-count">{{ $team['stats'][$key] }}</span>
                    </button>
                @endif
            @endforeach
        </nav>

        <div class="ctw-section-heading">
            <div>
                <h2>{{ $tabs[$activeTab]['label'] ?? 'Pessoas' }}</h2>
                <p>{{ match ($activeTab) { 'roles' => 'Configure por módulo, com ações e alcance claros. Alterar um papel atualiza todas as pessoas que o utilizam.', 'bpo' => 'Autorize o responsável e acompanhe os acessos que dependem de cada atendimento.', 'invitations' => 'Acompanhe quem ainda precisa aceitar o convite para entrar na empresa.', default => 'Papéis, situação e forma de concessão reunidos no perfil de cada pessoa.' } }}</p>
            </div>
            <div class="ctw-actions">
                @if ($activeTab === 'roles' && $manager) {{ $this->templatesAction }} {{ $this->roleAction }} @endif
                @if ($activeTab === 'bpo' && $manager) {{ $this->bpoAction }} @endif
            </div>
        </div>

        <label class="ctw-search"><x-filament::icon icon="heroicon-o-magnifying-glass" /><span class="ctw-sr-only">Buscar nesta seção</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $activeTab === 'people' ? 'Buscar pessoa por nome, e-mail ou papel…' : ($activeTab === 'roles' ? 'Buscar papel pelo nome…' : 'Buscar por nome ou e-mail…') }}" autocomplete="off" /><span wire:loading wire:target="search" class="ctw-muted">Buscando…</span></label>

        @if ($activeTab === 'people')
            <div class="ctw-workspace" wire:key="people-workspace">
                <aside class="ctw-list" aria-label="Pessoas da empresa">
                    <div class="ctw-list-heading"><span>{{ $team['members']->count() }} {{ $team['members']->count() === 1 ? 'pessoa' : 'pessoas' }}</span><span>Selecione para gerenciar</span></div>
                    @forelse ($team['members'] as $member)
                        @php($personRoles = $team['assignments']->where('user_id', $member->id)->pluck('role.name')->unique())
                        <button type="button" class="ctw-list-item {{ $person?->id === $member->id ? 'is-selected' : '' }}" wire:click="selectPerson({{ $member->id }})" aria-pressed="{{ $person?->id === $member->id ? 'true' : 'false' }}" wire:key="person-{{ $member->id }}">
                            <span class="ctw-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member->name, 0, 1)) }}</span>
                            <span class="ctw-person-name"><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small><span>{{ $personRoles->take(2)->join(' · ') ?: ($member->pivot->access_mode === 'legacy' ? 'Controle anterior' : 'Sem papéis ativos') }}{{ $personRoles->count() > 2 ? ' +'.($personRoles->count() - 2) : '' }}</span></span>
                            <span class="ctw-dot {{ $member->pivot->is_active && $member->is_active ? 'is-active' : '' }}" aria-label="{{ $member->pivot->is_active && $member->is_active ? 'Ativo' : 'Suspenso ou inativo' }}" title="{{ $member->pivot->is_active && $member->is_active ? 'Ativo' : 'Suspenso ou inativo' }}"></span>
                        </button>
                    @empty
                        <div class="ctw-empty"><x-filament::icon icon="heroicon-o-user-group" /><strong>Nenhuma pessoa encontrada</strong><p>{{ $search ? 'Tente outro nome, e-mail ou papel.' : 'Adicione uma pessoa cadastrada ou envie um convite para começar.' }}</p></div>
                    @endforelse
                </aside>
                <section class="ctw-detail" aria-label="Acessos da pessoa selecionada">
                    @if ($person)
                        <header class="ctw-profile">
                            <span class="ctw-avatar is-large">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($person->name, 0, 1)) }}</span>
                            <div><h3>{{ $person->name }}</h3><p>{{ $person->email }}</p><div class="ctw-badges"><span class="ctw-badge {{ $person->pivot->is_active && $person->is_active ? 'is-green' : 'is-neutral' }}">{{ ! $person->is_active ? 'Conta inativa' : ($person->pivot->is_active ? 'Vínculo ativo' : 'Vínculo suspenso') }}</span><span class="ctw-badge is-neutral">{{ $person->pivot->access_mode === 'roles' ? 'Acesso por papéis' : 'Controle anterior' }}</span></div></div>
                        </header>
                        @if (! $person->is_active || (! $manager && ! $person->pivot->is_active))
                            <div class="ctw-notice"><x-filament::icon icon="heroicon-o-information-circle" /><div><strong>{{ ! $person->is_active ? 'Conta desativada no sistema' : 'Vínculo suspenso pela empresa' }}</strong><p>{{ ! $person->is_active ? 'A administração do sistema precisa reativar a conta antes de conceder novos acessos.' : 'Somente um administrador da empresa pode reativar este vínculo. Os papéis não liberam o acesso enquanto ele estiver suspenso.' }}</p></div></div>
                        @endif
                        @if ($person->pivot->access_mode === 'legacy')
                            <div class="ctw-notice"><x-filament::icon icon="heroicon-o-information-circle" /><div><strong>O acesso atual foi preservado</strong><p>Esta pessoa ainda usa a configuração anterior. Ao editar os acessos concedidos pela empresa, selecione todos os papéis necessários para passar ao novo controle.</p></div></div>
                        @endif
                        <div class="ctw-detail-heading"><div><h4>Como o acesso foi concedido</h4><p>A mesma pessoa pode ter papéis concedidos pela empresa e por um BPO.</p></div></div>
                        @forelse ($team['personAssignments'] as $origin => $assignments)
                            @php($engagement = $assignments->first()->engagement)
                            <div class="ctw-grant">
                                <header><span class="ctw-grant-icon"><x-filament::icon :icon="$engagement ? 'heroicon-o-briefcase' : 'heroicon-o-building-office-2'" /></span><div><strong>{{ $engagement ? 'Pelo atendimento de '.$engagement->responsible->name : 'Concedido pela empresa' }}</strong><p>{{ $engagement ? 'Estes papéis deixam de valer ao encerrar este atendimento BPO.' : 'Não depende de BPO. Continua válido mesmo se o atendimento encerrar.' }}</p></div><x-filament::button size="sm" color="gray" :disabled="! $person->is_active || (! $manager && ! $person->pivot->is_active)" wire:click="mountAction('assign', { person: {{ $person->id }}, engagement: {{ $engagement?->id ?? 'null' }} })">Editar</x-filament::button></header>
                                <div class="ctw-role-pills">@foreach ($assignments as $assignment)<span class="ctw-role-pill" wire:key="grant-{{ $assignment->id }}">{{ $assignment->role->name }}<button type="button" wire:click="revokeAssignment({{ $assignment->id }})" wire:confirm="Remover apenas o papel '{{ $assignment->role->name }}' desta concessão de acesso? Os outros papéis serão mantidos." aria-label="Remover papel {{ $assignment->role->name }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></span>@endforeach</div>
                            </div>
                        @empty
                            <div class="ctw-empty is-compact"><x-filament::icon icon="heroicon-o-key" /><strong>{{ $person->pivot->access_mode === 'legacy' ? 'Nenhum papel do novo controle atribuído' : 'Nenhum papel ativo' }}</strong><p>{{ $person->pivot->access_mode === 'legacy' ? 'Os acessos anteriores continuam valendo até a migração para papéis.' : 'Atribua os papéis necessários para liberar as ações desta pessoa.' }}</p></div>
                        @endforelse
                        <div class="ctw-detail-actions">
                            <x-filament::button icon="heroicon-o-adjustments-horizontal" :disabled="! $person->is_active || (! $manager && ! $person->pivot->is_active)" wire:click="mountAction('assign', { person: {{ $person->id }} })">{{ $person->pivot->is_active ? 'Definir papéis' : 'Reativar e definir papéis' }}</x-filament::button>
                            @if ($manager && $person->pivot->access_mode === 'roles')<x-filament::button color="gray" icon="heroicon-o-eye" wire:click="mountAction('inspect', { person: {{ $person->id }} })">Ver permissões resultantes</x-filament::button>@endif
                        </div>
                        @if ($manager && $person->pivot->is_active)
                            <footer class="ctw-detail-footer"><p>Suspender bloqueia esta empresa para a pessoa, sem excluir sua conta ou seu histórico.</p><button type="button" class="ctw-danger-link" wire:click="suspendMember({{ $person->id }})" wire:confirm="Suspender o acesso de {{ $person->name }} a esta empresa?">Suspender acesso</button></footer>
                        @endif
                    @else
                        <div class="ctw-empty"><x-filament::icon icon="heroicon-o-cursor-arrow-rays" /><strong>Os acessos aparecem aqui</strong><p>Escolha uma pessoa na lista para consultar seus papéis e gerenciar o acesso.</p></div>
                    @endif
                </section>
            </div>
        @elseif ($activeTab === 'roles' && $manager)
            <div class="ctw-workspace" wire:key="roles-workspace">
                <aside class="ctw-list" aria-label="Papéis da empresa">
                    <div class="ctw-list-heading"><span>{{ $team['roles']->count() }} papéis</span><span>Selecione para consultar</span></div>
                    @forelse ($team['roles'] as $item)
                        <button type="button" class="ctw-list-item {{ $role?->id === $item->id ? 'is-selected' : '' }}" wire:click="selectRole({{ $item->id }})" aria-pressed="{{ $role?->id === $item->id ? 'true' : 'false' }}" wire:key="role-{{ $item->id }}"><span class="ctw-avatar is-square"><x-filament::icon icon="heroicon-o-key" /></span><span class="ctw-person-name"><strong>{{ $item->name }}</strong><small>{{ \App\Services\Access\PermissionCatalog::visiblePermissionCount($item->permissions) }} ações permitidas</small></span><x-filament::icon icon="heroicon-o-chevron-right" /></button>
                    @empty
                        <div class="ctw-empty"><x-filament::icon icon="heroicon-o-adjustments-horizontal" /><strong>{{ $search ? 'Nenhum papel encontrado' : 'Comece pelos papéis sugeridos' }}</strong><p>{{ $search ? 'Tente outro nome.' : 'Use os modelos de Financeiro, Vendedor e Técnico ou crie um papel personalizado.' }}</p>@if (! $search){{ $this->templatesAction }}@endif</div>
                    @endforelse
                </aside>
                <section class="ctw-detail" aria-label="Papel selecionado">
                    @if ($role)
                        <div class="ctw-detail-heading"><div><span class="ctw-eyebrow">Papel de acesso</span><h3>{{ $role->name }}</h3><p>{{ $team['roleAssignments']->count() }} {{ $team['roleAssignments']->count() === 1 ? 'pessoa utiliza' : 'pessoas utilizam' }} este papel.</p></div><x-filament::button icon="heroicon-o-pencil-square" wire:click="mountAction('role', { role_id: {{ $role->id }} })">Editar permissões</x-filament::button></div>
                        <div class="ctw-badges">@foreach ($role->panels as $panel)<span class="ctw-badge is-neutral">{{ ['admin' => 'Gestão completa', 'operation' => 'Operação', 'mobile' => 'Mobile'][$panel] ?? $panel }}</span>@endforeach</div>
                        <h4 class="ctw-subheading">Resumo por módulo</h4>
                        <div class="ctw-permission-summary">
                            @php($hasVisiblePermissions = false)
                            @foreach (\App\Services\Access\PermissionCatalog::editorGroups() as $group)
                                @php($granted = collect($group['permissions'])->filter(fn ($permission) => isset($role->permissions[$permission['key']]) && $role->permissions[$permission['key']] !== 'none'))
                                @if ($granted->isNotEmpty())
                                    @php($hasVisiblePermissions = true)
                                    <details class="ctw-module-summary"><summary><span><small>{{ $group['area'] }}</small><strong>{{ $group['label'] }}</strong></span><span class="ctw-badge is-blue">{{ $granted->count() }} ações</span><x-filament::icon icon="heroicon-o-chevron-down" /></summary><dl>@foreach ($granted as $permission)<div><dt>{{ $permission['label'] }}</dt><dd>{{ $permission['options'][$role->permissions[$permission['key']]] ?? $role->permissions[$permission['key']] }}</dd></div>@endforeach</dl></details>
                                @endif
                            @endforeach
                            @if (! $hasVisiblePermissions)<div class="ctw-empty is-compact"><strong>Nenhuma ação permitida</strong><p>Abra Editar permissões para configurar os módulos deste papel.</p></div>@endif
                        </div>
                        @if ($team['roleAssignments']->isNotEmpty())<div class="ctw-detail-footer"><div><strong>Pessoas com este papel</strong><p>{{ $team['roleAssignments']->pluck('user.name')->join(', ') }}</p></div></div>@endif
                    @else
                        <div class="ctw-empty"><x-filament::icon icon="heroicon-o-key" /><strong>Crie o primeiro papel</strong><p>Você define as ações por módulo e depois atribui o papel às pessoas.</p></div>
                    @endif
                </section>
            </div>
        @elseif ($activeTab === 'bpo')
            <div class="ctw-notice"><x-filament::icon icon="heroicon-o-information-circle" /><div><strong>Acesso pela empresa ou pelo BPO?</strong><p>“Concedido pela empresa” é um vínculo independente. “Pelo atendimento BPO” depende da autorização abaixo. Encerrar um atendimento remove somente os acessos concedidos por ele.</p></div></div>
            <div class="ctw-cards">
                @forelse ($team['engagements'] as $engagement)
                    @php($engagementAssignments = $team['assignments']->where('bpo_engagement_id', $engagement->id))
                    <article class="ctw-card" wire:key="engagement-{{ $engagement->id }}"><div class="ctw-detail-heading"><span class="ctw-avatar is-square"><x-filament::icon icon="heroicon-o-briefcase" /></span><span class="ctw-badge {{ $engagement->is_active ? 'is-green' : 'is-neutral' }}">{{ $engagement->is_active ? 'Em atendimento' : 'Encerrado' }}</span></div><h3>{{ $engagement->responsible->name }}</h3><p class="ctw-muted">{{ $engagement->responsible->email }}</p><div class="ctw-card-metrics"><div><strong>{{ $engagementAssignments->unique('user_id')->count() }}</strong><span>Pessoas neste atendimento</span></div><div><strong>{{ $engagement->can_delegate ? 'Permitida' : 'Não permitida' }}</strong><span>Delegação para auxiliares</span></div></div><div class="ctw-role-pills">@foreach ($engagementAssignments->where('user_id', $engagement->responsible_id)->pluck('role.name')->unique() as $roleName)<span class="ctw-badge is-blue">{{ $roleName }}</span>@endforeach</div><footer class="ctw-card-actions">@if ($engagement->is_active && ($manager || $engagement->can_delegate))<x-filament::button color="gray" size="sm" wire:click="mountAction('assign', { engagement: {{ $engagement->id }} })">Adicionar auxiliar</x-filament::button>@endif @if ($manager && $engagement->is_active)<button type="button" class="ctw-danger-link" wire:click="endBpo({{ $engagement->id }})" wire:confirm="Encerrar o atendimento de {{ $engagement->responsible->name }}? Os acessos deste atendimento serão revogados. Vínculos concedidos pela empresa serão mantidos.">Encerrar atendimento</button>@endif</footer></article>
                @empty
                    <div class="ctw-empty ctw-empty-wide"><x-filament::icon icon="heroicon-o-briefcase" /><strong>{{ $search ? 'Nenhum atendimento encontrado' : 'Nenhum atendimento BPO autorizado' }}</strong><p>{{ $search ? 'Tente outro nome ou e-mail.' : 'A empresa pode operar normalmente sem BPO. Quando precisar, autorize um responsável e defina o que ele poderá fazer.' }}</p></div>
                @endforelse
            </div>
        @elseif ($activeTab === 'invitations')
            <div class="ctw-invitations">
                @forelse ($team['invitations'] as $invitation)
                    <article class="ctw-invitation" wire:key="invitation-{{ $invitation->id }}"><span class="ctw-avatar is-square"><x-filament::icon icon="heroicon-o-envelope" /></span><div class="ctw-invitation-main"><strong>{{ $invitation->email }}</strong><p>{{ $invitation->expires_at->isPast() ? 'Expirou em ' : 'Aguardando aceite · válido até ' }}{{ $invitation->expires_at->format('d/m/Y H:i') }}</p><small>{{ $invitation->bpo_engagement_id ? 'Convidado por um atendimento BPO' : 'Convidado pela empresa' }}</small></div><span class="ctw-badge {{ $invitation->expires_at->isPast() ? 'is-neutral' : 'is-blue' }}">{{ $invitation->expires_at->isPast() ? 'Expirado' : 'Pendente' }}</span><div class="ctw-actions"><x-filament::button size="sm" color="gray" wire:click="mountAction('invite', { invitation: {{ $invitation->id }} })">Enviar novo convite</x-filament::button><button type="button" class="ctw-danger-link" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="Cancelar o convite para {{ $invitation->email }}?">Cancelar convite</button></div></article>
                @empty
                    <div class="ctw-empty"><x-filament::icon icon="heroicon-o-envelope-open" /><strong>{{ $search ? 'Nenhum convite encontrado' : 'Nenhum convite aguardando aceite' }}</strong><p>{{ $search ? 'Tente outro e-mail.' : 'Quando você convidar alguém, acompanhe aqui a validade e o aceite.' }}</p></div>
                @endforelse
            </div>
        @endif
    </div>
    @include('filament.pages.company-team-styles')
</x-filament-panels::page>
