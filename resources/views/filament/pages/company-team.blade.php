<x-filament-panels::page>
    @php($team = $this->teamData())
    <div class="flex flex-wrap gap-3">
        {{ $this->templatesAction }} {{ $this->roleAction }} {{ $this->assignAction }} {{ $this->inviteAction }} {{ $this->bpoAction }}
    </div>
    @if ($invitationUrl)
        <x-filament::section heading="Convite criado">
            <p>Compartilhe este link somente com o destinatário. Ele deve entrar com o e-mail convidado e confirmar o aceite.</p>
            <a class="break-all underline" href="{{ $invitationUrl }}">{{ $invitationUrl }}</a>
        </x-filament::section>
    @endif
    <x-filament::section heading="Papéis da empresa">
        <p>Os papéis acumulam permissões. “Todos” significa todos os registros desta empresa. Próprios são os criados pelo usuário; atribuídos são os vinculados a ele como responsável.</p>
        @forelse ($team['roles'] as $role)
            <details class="border-b py-3"><summary>{{ $role->name }} — {{ count($role->permissions) }} permissões</summary>
                <ul>@foreach ($role->permissions as $permission => $scope)<li>{{ \App\Services\Access\PermissionCatalog::labels()[$permission] ?? $permission }}: {{ \App\Enum\User\AccessScope::options()[$scope] ?? $scope }}</li>@endforeach</ul>
            </details>
        @empty <p>Nenhum papel disponível para exibição. O administrador pode adicionar modelos ou criar papéis.</p> @endforelse
    </x-filament::section>
    @if ($this->isManager())
        <x-filament::section heading="Usuários vinculados">
            @foreach ($team['members'] as $member)
                <div class="flex flex-wrap justify-between gap-3 border-b py-3">
                    <span>{{ $member->name }} — {{ $member->email }} — {{ $member->pivot->is_active ? 'Ativo' : 'Suspenso' }} — {{ $member->pivot->access_mode === 'legacy' ? 'Acesso anterior preservado' : 'Acesso por papéis' }}</span>
                    @if ($member->pivot->is_active)<x-filament::button color="danger" size="sm" wire:click="suspendMember({{ $member->id }})" wire:confirm="Suspender todos os acessos deste usuário nesta empresa?">Suspender vínculo</x-filament::button>@endif
                </div>
            @endforeach
            <p class="mt-3">Atribuir papéis migra este usuário para o novo controle nesta empresa. Revise todas as permissões necessárias antes de confirmar. Para reativar um vínculo, atribua os papéis novamente.</p>
        </x-filament::section>
    @endif
    <x-filament::section heading="Atribuições e origem dos acessos">
        @forelse ($team['assignments'] as $assignment)
            <div class="flex flex-wrap justify-between gap-3 border-b py-3">
                <span>{{ $assignment->user->name }} — {{ $assignment->role->name }} — {{ $assignment->bpo_engagement_id ? 'Atendimento BPO #'.$assignment->bpo_engagement_id : 'Direto' }}</span>
                <x-filament::button color="danger" size="sm" wire:click="revokeAssignment({{ $assignment->id }})" wire:confirm="Revogar esta atribuição?">Revogar papel</x-filament::button>
            </div>
        @empty <p>Nenhuma atribuição ativa.</p> @endforelse
    </x-filament::section>
    <x-filament::section heading="Atendimentos BPO">
        @forelse ($team['engagements'] as $engagement)
            <div class="flex flex-wrap justify-between gap-3 border-b py-3">
                <span>#{{ $engagement->id }} — {{ $engagement->responsible->name }} — {{ $engagement->is_active ? 'Ativo' : 'Encerrado' }} — {{ $engagement->can_delegate ? 'Pode delegar ações autorizadas' : 'Sem delegação' }}</span>
                @if ($this->isManager() && $engagement->is_active)<x-filament::button color="danger" size="sm" wire:click="endBpo({{ $engagement->id }})" wire:confirm="Encerrar o atendimento e revogar os acessos originados nele?">Encerrar</x-filament::button>@endif
            </div>
        @empty <p>Nenhum atendimento BPO.</p> @endforelse
    </x-filament::section>
    <x-filament::section heading="Convites pendentes">
        @forelse ($team['invitations'] as $invitation)
            <div class="flex flex-wrap justify-between gap-3 border-b py-3">
                <span>{{ $invitation->email }} — vence em {{ $invitation->expires_at->format('d/m/Y H:i') }}</span>
                <x-filament::button color="danger" size="sm" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="Revogar este convite?">Revogar</x-filament::button>
            </div>
        @empty <p>Nenhum convite pendente.</p> @endforelse
    </x-filament::section>
</x-filament-panels::page>
