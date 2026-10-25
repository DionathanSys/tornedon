<?php

namespace App\Filament\Pages;

use App\Forms\Components\CompanyPermissionWorkbench;
use App\Models\BpoEngagement;
use App\Models\Company;
use App\Models\CompanyAccessInvitation;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\CompanyTeamService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CompanyTeam extends Page
{
    protected string $view = 'filament.pages.company-team';

    protected static ?string $title = 'Equipe e acessos';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurações';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public ?string $invitationUrl = null;

    public string $activeTab = 'people';

    public string $search = '';

    #[Locked]
    public ?int $selectedPersonId = null;

    #[Locked]
    public ?int $selectedRoleId = null;

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();
        $user = Filament::auth()->user();

        return $company instanceof Company && $user && (app(CompanyAuthorization::class)->canManageTeam($user, $company)
            || BpoEngagement::where('company_id', $company->id)->where('responsible_id', $user->id)->where('is_active', true)->where('can_delegate', true)->exists()
                && $user->canAccessTenant($company));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function isManager(): bool
    {
        return app(CompanyAuthorization::class)->canManageTeam(auth()->user(), Filament::getTenant());
    }

    public function changeTab(string $tab): void
    {
        abort_unless(static::canAccess() && in_array($tab, ['people', 'roles', 'bpo', 'invitations'], true), 403);
        abort_if($tab === 'roles' && ! $this->isManager(), 403);
        $this->activeTab = $tab;
        $this->search = '';
    }

    public function selectPerson(int $id): void
    {
        $this->findVisiblePerson($id);
        $this->selectedPersonId = $id;
    }

    public function selectRole(int $id): void
    {
        $this->findRole($id);
        $this->selectedRoleId = $id;
    }

    public function roleAction(): Action
    {
        return Action::make('role')->label('Novo papel')->icon('heroicon-o-plus')->visible(fn () => $this->isManager())
            ->modalHeading(fn (array $arguments) => empty($arguments['role_id']) ? 'Criar papel de acesso' : 'Editar papel de acesso')
            ->modalDescription('Defina os painéis e escolha as ações de cada módulo. As permissões deste papel se somam às dos outros papéis da pessoa.')
            ->modalWidth('7xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Salvar papel')
            ->fillForm(function (array $arguments): array {
                $role = empty($arguments['role_id']) ? null : $this->findRole((int) $arguments['role_id']);

                return ['role_id' => $role?->id, 'name' => $role?->name, 'panels' => $role?->panels ?? ['admin', 'operation', 'mobile'], 'permissions' => $role?->permissions ?? []];
            })
            ->schema([
                Hidden::make('role_id'),
                Section::make()->schema([
                    TextInput::make('name')->label('Nome do papel')->placeholder('Ex.: Financeiro, Vendedor, Técnico')->required()->maxLength(100),
                    CheckboxList::make('panels')->label('Onde a pessoa poderá trabalhar')
                        ->options(['admin' => 'Gestão completa (Admin)', 'operation' => 'Operação', 'mobile' => 'Mobile'])
                        ->columns(3)->required()->helperText('O painel dá acesso à interface. As ações permitidas são definidas abaixo.'),
                ])->columns(2),
                CompanyPermissionWorkbench::make('permissions')->label('Permissões por módulo')->default([])->rules(['array']),
            ])->action(function (array $data): void {
                $role = app(CompanyTeamService::class)->saveRole(auth()->user(), Filament::getTenant(), [
                    'name' => $data['name'], 'panels' => $data['panels'], 'permissions' => Arr::dot($data['permissions'] ?? []),
                ], filled($data['role_id'] ?? null) ? (int) $data['role_id'] : null);
                $this->selectedRoleId = $role->id;
                $this->activeTab = 'roles';
                $this->search = '';
                $this->success('Papel salvo');
            });
    }

    public function templatesAction(): Action
    {
        return Action::make('templates')->label('Usar papéis sugeridos')->icon('heroicon-o-squares-plus')->color('gray')->visible(fn () => $this->isManager())
            ->modalHeading('Adicionar papéis sugeridos')->modalDescription('Inclui Administrador, Financeiro, Vendedor, Técnico e outros modelos. Seus papéis existentes são preservados e nenhum acesso é atribuído automaticamente.')
            ->requiresConfirmation()->action(function (): void {
                app(CompanyTeamService::class)->installTemplates(auth()->user(), Filament::getTenant());
                $this->activeTab = 'roles';
                $this->success('Papéis sugeridos disponíveis para configurar');
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')->label('Adicionar pessoa cadastrada')->icon('heroicon-o-user-plus')->color('gray')
            ->modalHeading(fn (array $arguments) => empty($arguments['person']) ? 'Dar acesso a uma pessoa cadastrada' : 'Editar acessos da pessoa')
            ->modalDescription('Escolha quem concede o acesso e os papéis dessa pessoa. Ao salvar, apenas os papéis dessa concessão são substituídos; os demais vínculos permanecem.')
            ->modalWidth('3xl')->modalSubmitActionLabel('Salvar acessos')
            ->fillForm(function (array $arguments): array {
                $person = empty($arguments['person']) ? null : $this->findVisiblePerson((int) $arguments['person']);
                $engagement = $this->resolveEngagementArgument($arguments);

                return ['email' => $person?->email, 'person_locked' => $person !== null, 'engagement_id' => $engagement,
                    'role_ids' => $person ? $this->currentRoleIds($person->email, $engagement) : []];
            })
            ->schema([
                Hidden::make('person_locked')->default(false),
                TextInput::make('email')->label('E-mail da pessoa')->email()->required()->live(onBlur: true)
                    ->readOnly(fn (Get $get) => (bool) $get('person_locked'))
                    ->helperText('Já deve possuir uma conta no sistema. Se ainda não possui, use Convidar pessoa.')
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        if (empty($get('role_ids'))) {
                            $set('role_ids', $this->currentRoleIds($get('email'), $get('engagement_id')));
                        }
                    }),
                $this->engagementSelect()->live()->afterStateUpdated(function (Get $get, Set $set, $state, $old): void {
                    if ((string) $state !== (string) $old) {
                        $set('role_ids', $this->currentRoleIds($get('email'), $get('engagement_id')));
                    }
                }),
                $this->roleSelection('Papéis desta pessoa'),
                Section::make('Como ficam os acessos')->description(function (Get $get): string {
                    if ($get('engagement_id')) {
                        return 'Este acesso depende do atendimento BPO selecionado. Ao encerrar o atendimento, os papéis concedidos por ele deixam de valer.';
                    }

                    return 'Acesso concedido pela própria empresa, sem depender de BPO. Se esta pessoa ainda usa o controle anterior, salvar os papéis ativa o novo controle para ela nesta empresa. Selecione todos os papéis necessários.';
                })->schema([]),
            ])->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                $target = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
                if (! $target) {
                    throw ValidationException::withMessages(['email' => 'Pessoa não cadastrada. Use Convidar pessoa para enviar um convite.']);
                }
                if (! $target->isActive()) {
                    throw ValidationException::withMessages(['email' => 'Esta conta está inativa. A administração do sistema precisa reativá-la antes de conceder novos acessos.']);
                }
                app(CompanyTeamService::class)->assign(auth()->user(), Filament::getTenant(), $target, $data['role_ids'], filled($data['engagement_id'] ?? null) ? (int) $data['engagement_id'] : null);
                $this->selectedPersonId = $target->id;
                $this->success('Acessos da pessoa atualizados');
            });
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')->label('Convidar pessoa')->icon('heroicon-o-envelope')
            ->modalHeading('Convidar pessoa para a empresa')->modalDescription('A pessoa recebe um convite por e-mail e utiliza a mesma conta, caso já tenha cadastro. O convite é válido por 7 dias.')
            ->modalWidth('3xl')->modalSubmitActionLabel('Criar e enviar convite')
            ->fillForm(function (array $arguments): array {
                $invitation = empty($arguments['invitation']) ? null : $this->visibleInvitations()->findOrFail((int) $arguments['invitation']);

                return ['email' => $invitation?->email, 'role_ids' => $invitation?->role_ids ?? [],
                    'engagement_id' => $invitation?->bpo_engagement_id ?? $this->resolveEngagementArgument([])];
            })
            ->schema([
                TextInput::make('email')->label('E-mail da pessoa')->email()->required(),
                $this->engagementSelect(),
                $this->roleSelection('Papéis ao aceitar o convite'),
            ])->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                $this->invitationUrl = app(CompanyTeamService::class)->invite(auth()->user(), Filament::getTenant(), $data['email'], $data['role_ids'], filled($data['engagement_id'] ?? null) ? (int) $data['engagement_id'] : null);
                $this->success('Convite criado. O link também está disponível nesta página.');
            });
    }

    public function bpoAction(): Action
    {
        return Action::make('bpo')->label('Autorizar atendimento BPO')->icon('heroicon-o-briefcase')->visible(fn () => $this->isManager())
            ->modalHeading('Autorizar atendimento BPO')->modalDescription('A empresa autoriza uma pessoa a cuidar da operação e, se desejar, permite que ela distribua ações específicas para auxiliares.')
            ->modalWidth('7xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Autorizar atendimento')
            ->schema([
                TextInput::make('email')->label('E-mail do responsável BPO')->helperText('O responsável deve ter uma conta cadastrada no sistema.')->email()->required(),
                $this->roleSelection('Papéis do responsável BPO')->live(),
                Toggle::make('can_delegate')->label('Permitir que o responsável dê acesso a auxiliares')->live()->default(false)
                    ->helperText('Abaixo aparecem somente ações dos papéis selecionados com alcance de empresa. Você escolhe quais podem ser delegadas.'),
                CompanyPermissionWorkbench::make('delegable_permissions')->label('Quais ações ele poderá delegar?')->delegation()->default([])
                    ->availablePermissions(function (Get $get): array {
                        return CompanyRole::where('company_id', Filament::getTenant()->id)->whereIn('id', $get('role_ids') ?? [])->get()
                            ->flatMap(fn ($role) => array_keys(array_filter($role->permissions, fn ($scope) => $scope === 'company')))->unique()->values()->all();
                    })->visible(fn (Get $get) => (bool) $get('can_delegate')),
            ])->action(function (array $data): void {
                $target = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
                if (! $target) {
                    throw ValidationException::withMessages(['email' => 'Responsável não cadastrado. Convide a pessoa para criar uma conta antes de autorizar o atendimento.']);
                }
                $available = CompanyRole::where('company_id', Filament::getTenant()->id)->whereIn('id', $data['role_ids'])->get()
                    ->flatMap(fn ($role) => array_keys(array_filter($role->permissions, fn ($scope) => $scope === 'company')))->unique()->all();
                $ceiling = array_intersect_key(Arr::dot($data['delegable_permissions'] ?? []), array_flip($available));
                app(CompanyTeamService::class)->authorizeBpo(auth()->user(), Filament::getTenant(), $target, $data['role_ids'], (bool) $data['can_delegate'],
                    $data['can_delegate'] ? $ceiling : []);
                $this->success('Atendimento BPO autorizado');
            });
    }

    public function inspectAction(): Action
    {
        return Action::make('inspect')->label('Ver permissões da pessoa')->icon('heroicon-o-eye')->color('gray')->visible(fn () => $this->isManager())
            ->modalHeading('Permissões atuais na empresa')->modalDescription('Resultado da soma dos papéis ativos, respeitando a situação do vínculo e as autorizações BPO.')
            ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
            ->fillForm(function (array $arguments): array {
                abort_unless($this->isManager(), 403);
                $person = $this->findVisiblePerson((int) ($arguments['person'] ?? 0));
                abort_unless($person->pivot->access_mode === 'roles', 403);

                return ['permissions' => app(CompanyAuthorization::class)->effectivePermissions($person, Filament::getTenant())];
            })->schema([CompanyPermissionWorkbench::make('permissions')->hiddenLabel()->disabled()]);
    }

    public function revokeAssignment(int $id): void
    {
        app(CompanyTeamService::class)->revokeAssignment(auth()->user(), Filament::getTenant(), $id);
        $this->success('Papel removido desta concessão de acesso');
    }

    public function suspendMember(int $id): void
    {
        app(CompanyTeamService::class)->suspend(auth()->user(), Filament::getTenant(), User::findOrFail($id));
        $this->success('Acesso à empresa suspenso');
    }

    public function endBpo(int $id): void
    {
        app(CompanyTeamService::class)->endBpo(auth()->user(), Filament::getTenant(), $id);
        $this->success('Atendimento encerrado. Acessos concedidos pela empresa foram preservados.');
    }

    public function revokeInvitation(int $id): void
    {
        app(CompanyTeamService::class)->revokeInvitation(auth()->user(), Filament::getTenant(), $id);
        $this->success('Convite cancelado');
    }

    public function teamData(): array
    {
        abort_unless(static::canAccess(), 403);
        $engagements = $this->visibleEngagements()->with('responsible')->get();
        $assignments = $this->visibleAssignments()->with(['role', 'user', 'engagement.responsible'])->get();
        $members = $this->visiblePeople()->get();
        $roles = $this->isManager() ? CompanyRole::where('company_id', Filament::getTenant()->id)->orderBy('name')->get() : collect();
        $invitations = $this->visibleInvitations()->with('company')->latest()->get();
        $peopleResults = $members->filter(fn ($member) => $this->matchesSearch($member->name.' '.$member->email.' '.$assignments->where('user_id', $member->id)->pluck('role.name')->join(' ')));
        $roleResults = $roles->filter(fn ($role) => $this->matchesSearch($role->name));
        $selectedPerson = $peopleResults->firstWhere('id', $this->selectedPersonId) ?? $peopleResults->first();
        $selectedRole = $roleResults->firstWhere('id', $this->selectedRoleId) ?? $roleResults->first();

        return [
            'members' => $peopleResults, 'roles' => $roleResults, 'assignments' => $assignments,
            'engagements' => $engagements->filter(fn ($bpo) => $this->matchesSearch($bpo->responsible->name.' '.$bpo->responsible->email)),
            'invitations' => $invitations->filter(fn ($invitation) => $this->matchesSearch($invitation->email)),
            'selectedPerson' => $selectedPerson, 'selectedRole' => $selectedRole,
            'personAssignments' => $assignments->where('user_id', $selectedPerson?->id)->groupBy(fn ($assignment) => $assignment->bpo_engagement_id ?? 'company'),
            'roleAssignments' => $assignments->where('company_role_id', $selectedRole?->id)->unique('user_id'),
            'stats' => ['people' => $members->count(), 'roles' => $roles->count(), 'bpo' => $engagements->where('is_active', true)->count(),
                'invitations' => $invitations->filter(fn ($invitation) => $invitation->expires_at->isFuture())->count()],
        ];
    }

    private function visibleEngagements()
    {
        abort_unless(static::canAccess(), 403);
        $query = BpoEngagement::where('company_id', Filament::getTenant()->id);
        if (! $this->isManager()) {
            $query->where('responsible_id', auth()->id());
        }

        return $query;
    }

    private function visibleAssignments()
    {
        $query = CompanyRoleAssignment::where('company_id', Filament::getTenant()->id)->where('is_active', true);
        if (! $this->isManager()) {
            $query->whereIn('bpo_engagement_id', $this->visibleEngagements()->select('id'));
        }

        return $query;
    }

    private function visiblePeople()
    {
        abort_unless(static::canAccess(), 403);
        $query = Filament::getTenant()->users()->withPivot(['role', 'is_active', 'access_mode'])->orderBy('name');
        if (! $this->isManager()) {
            $query->whereIn('users.id', $this->visibleAssignments()->select('user_id'));
        }

        return $query;
    }

    private function visibleInvitations()
    {
        abort_unless(static::canAccess(), 403);
        $query = CompanyAccessInvitation::where('company_id', Filament::getTenant()->id)->whereNull('accepted_at')->whereNull('revoked_at');
        if (! $this->isManager()) {
            $query->whereIn('bpo_engagement_id', $this->visibleEngagements()->select('id'));
        }

        return $query;
    }

    private function findVisiblePerson(int $id): User
    {
        $person = $this->visiblePeople()->where('users.id', $id)->first();
        abort_unless($person, 404);

        return $person;
    }

    private function findRole(int $id): CompanyRole
    {
        abort_unless($this->isManager(), 403);

        $role = CompanyRole::where('company_id', Filament::getTenant()->id)->find($id);
        abort_unless($role, 404);

        return $role;
    }

    private function roleOptions(mixed $engagementId = null): array
    {
        abort_unless(static::canAccess(), 403);
        $roles = CompanyRole::where('company_id', Filament::getTenant()->id)->where('is_active', true)->orderBy('name')->get();
        if (! $this->isManager()) {
            $engagement = $this->visibleEngagements()->where('is_active', true)->where('can_delegate', true)
                ->when(filled($engagementId), fn ($query) => $query->whereKey((int) $engagementId))->first();
            $permissions = app(CompanyAuthorization::class)->effectivePermissions(auth()->user(), Filament::getTenant());
            $roles = $roles->filter(fn ($role) => $engagement && $role->permissions !== [] && collect($role->permissions)->every(fn ($scope, $key) => $key !== 'team.manage' && ($engagement->delegable_permissions[$key] ?? null) === 'company'
                && in_array('company', $permissions[$key] ?? [], true)));
        }

        return $roles->pluck('name', 'id')->all();
    }

    private function roleSelection(string $label): CheckboxList
    {
        return CheckboxList::make('role_ids')->label($label)->options(fn (Get $get) => $this->roleOptions($get('engagement_id')))->columns(2)->searchable()->required()
            ->helperText(fn () => $this->isManager()
                ? 'Você pode selecionar vários papéis. Para consultar ou ajustar suas ações, abra a aba Papéis e permissões.'
                : 'São exibidos apenas os papéis que você pode delegar neste atendimento, conforme a autorização da empresa.');
    }

    private function engagementSelect(): Select
    {
        return Select::make('engagement_id')->label('Quem concede este acesso?')->placeholder(fn () => $this->isManager() ? 'A própria empresa (sem BPO)' : 'Selecione seu atendimento BPO')
            ->helperText('Pela empresa: o acesso continua mesmo se o BPO sair. Pelo BPO: depende daquele atendimento.')
            ->required(fn () => ! $this->isManager())->options(fn () => $this->visibleEngagements()->where('is_active', true)
            ->when(! $this->isManager(), fn ($query) => $query->where('can_delegate', true))->with('responsible')->get()
            ->mapWithKeys(fn ($bpo) => [$bpo->id => 'Atendimento de '.$bpo->responsible->name])->all());
    }

    private function resolveEngagementArgument(array $arguments): ?int
    {
        if (! empty($arguments['engagement'])) {
            return (int) $this->visibleEngagements()->where('is_active', true)
                ->when(! $this->isManager(), fn ($query) => $query->where('can_delegate', true))->findOrFail((int) $arguments['engagement'])->id;
        }

        return $this->isManager() ? null : $this->visibleEngagements()->where('is_active', true)->where('can_delegate', true)->value('id');
    }

    private function currentRoleIds(?string $email, mixed $engagementId): array
    {
        abort_unless(static::canAccess(), 403);
        if (! filled($email)) {
            return [];
        }
        $query = $this->visibleAssignments()->whereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]));
        filled($engagementId) ? $query->where('bpo_engagement_id', (int) $engagementId) : $query->whereNull('bpo_engagement_id');

        return $query->pluck('company_role_id')->all();
    }

    private function matchesSearch(string $value): bool
    {
        return str_contains(Str::lower(Str::ascii($value)), Str::lower(Str::ascii(trim($this->search))));
    }

    private function success(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }
}
