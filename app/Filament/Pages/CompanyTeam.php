<?php

namespace App\Filament\Pages;

use App\Models\BpoEngagement;
use App\Models\Company;
use App\Models\CompanyAccessInvitation;
use App\Models\CompanyRole;
use App\Models\CompanyRoleAssignment;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

class CompanyTeam extends Page
{
    protected string $view = 'filament.pages.company-team';

    protected static ?string $title = 'Equipe e acessos';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurações';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public ?string $invitationUrl = null;

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

    public function roleAction(): Action
    {
        return Action::make('role')->label('Criar / editar papel')->visible(fn () => $this->isManager())
            ->schema([
                Select::make('role_id')->label('Papel existente (vazio para criar)')->options(fn () => $this->roleOptions())->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $role = $state ? CompanyRole::where('company_id', Filament::getTenant()->id)->findOrFail($state) : null;
                        $set('name', $role?->name);
                        $set('panels', $role?->panels ?? ['admin', 'operation', 'mobile']);
                        $set('grants', collect($role?->permissions ?? [])->map(fn ($scope, $permission) => ['permission' => $permission, 'scope' => $scope])->values()->all());
                    }),
                TextInput::make('name')->label('Nome')->required()->maxLength(100),
                CheckboxList::make('panels')->label('Painéis')->options(['admin' => 'Admin', 'operation' => 'Operação', 'mobile' => 'Mobile'])
                    ->default(['admin', 'operation', 'mobile'])->required(),
                $this->permissionRepeater('grants'),
            ])->action(function (array $data): void {
                app(CompanyTeamService::class)->saveRole(auth()->user(), Filament::getTenant(), [
                    'name' => $data['name'], 'panels' => $data['panels'], 'permissions' => $this->permissionMap($data['grants'] ?? []),
                ], $data['role_id'] ? (int) $data['role_id'] : null);
                $this->success();
            });
    }

    public function templatesAction(): Action
    {
        return Action::make('templates')->label('Adicionar modelos de papéis')->visible(fn () => $this->isManager())
            ->requiresConfirmation()->action(function (): void {
                app(CompanyTeamService::class)->installTemplates(auth()->user(), Filament::getTenant());
                $this->success();
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')->label('Atribuir papéis')->schema([
            TextInput::make('email')->label('E-mail do usuário cadastrado')->email()->required(),
            Select::make('role_ids')->label('Papéis (substitui atribuições da origem selecionada)')->multiple()->options(fn () => $this->roleOptions())->required(),
            $this->engagementSelect(),
        ])->action(function (array $data): void {
            abort_unless(static::canAccess(), 403);
            $target = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
            if (! $target) {
                throw ValidationException::withMessages(['email' => 'Envie um convite para este e-mail.']);
            }
            app(CompanyTeamService::class)->assign(auth()->user(), Filament::getTenant(), $target, $data['role_ids'], $data['engagement_id'] ? (int) $data['engagement_id'] : null);
            $this->success();
        });
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')->label('Convidar por e-mail')->schema([
            TextInput::make('email')->label('E-mail')->email()->required(),
            Select::make('role_ids')->label('Papéis')->multiple()->options(fn () => $this->roleOptions())->required(),
            $this->engagementSelect(),
        ])->action(function (array $data): void {
            abort_unless(static::canAccess(), 403);
            $this->invitationUrl = app(CompanyTeamService::class)->invite(auth()->user(), Filament::getTenant(), $data['email'], $data['role_ids'], $data['engagement_id'] ? (int) $data['engagement_id'] : null);
            Notification::make()->title('Convite criado: compartilhe o link com o destinatário. Válido por 7 dias.')->success()->send();
        });
    }

    public function bpoAction(): Action
    {
        return Action::make('bpo')->label('Autorizar BPO')->visible(fn () => $this->isManager())->schema([
            TextInput::make('email')->label('E-mail do responsável BPO cadastrado')->email()->required(),
            Select::make('role_ids')->label('Papéis do responsável')->multiple()->options(fn () => $this->roleOptions())->required(),
            Toggle::make('can_delegate')->label('Permitir que o BPO delegue acesso a auxiliares'),
            CheckboxList::make('delegable')->label('Ações que podem ser delegadas (alcance máximo: todos da empresa)')
                ->options(array_diff_key(PermissionCatalog::labels(), ['team.manage' => true])),
        ])->action(function (array $data): void {
            app(CompanyTeamService::class)->authorizeBpo(auth()->user(), Filament::getTenant(),
                User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->firstOrFail(), $data['role_ids'], (bool) $data['can_delegate'],
                array_fill_keys($data['delegable'] ?? [], 'company'));
            $this->success();
        });
    }

    public function revokeAssignment(int $id): void
    {
        app(CompanyTeamService::class)->revokeAssignment(auth()->user(), Filament::getTenant(), $id);
        $this->success();
    }

    public function suspendMember(int $id): void
    {
        app(CompanyTeamService::class)->suspend(auth()->user(), Filament::getTenant(), User::findOrFail($id));
        $this->success();
    }

    public function endBpo(int $id): void
    {
        app(CompanyTeamService::class)->endBpo(auth()->user(), Filament::getTenant(), $id);
        $this->success();
    }

    public function revokeInvitation(int $id): void
    {
        app(CompanyTeamService::class)->revokeInvitation(auth()->user(), Filament::getTenant(), $id);
        $this->success();
    }

    public function teamData(): array
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        $engagements = BpoEngagement::where('company_id', $company->id)->with('responsible');
        if (! $this->isManager()) {
            $engagements->where('responsible_id', auth()->id());
        }
        $engagements = $engagements->get();
        $assignments = CompanyRoleAssignment::where('company_id', $company->id)->where('is_active', true)->with(['role', 'user']);
        $invitations = CompanyAccessInvitation::where('company_id', $company->id)->whereNull('accepted_at')->whereNull('revoked_at');
        if (! $this->isManager()) {
            $assignments->whereIn('bpo_engagement_id', $engagements->modelKeys());
            $invitations->whereIn('bpo_engagement_id', $engagements->modelKeys());
        }

        return [
            'roles' => $this->isManager() ? CompanyRole::where('company_id', $company->id)->get() : collect(),
            'members' => $this->isManager() ? $company->users()->withPivot(['role', 'is_active', 'access_mode'])->get() : collect(),
            'assignments' => $assignments->get(), 'engagements' => $engagements, 'invitations' => $invitations->get(),
        ];
    }

    private function roleOptions(): array
    {
        abort_unless(static::canAccess(), 403);

        return CompanyRole::where('company_id', Filament::getTenant()->id)->where('is_active', true)->pluck('name', 'id')->all();
    }

    private function engagementSelect(): Select
    {
        return Select::make('engagement_id')->label('Origem do acesso')->placeholder('Vínculo direto com a empresa')
            ->required(fn () => ! $this->isManager())->options(function (): array {
                $query = BpoEngagement::where('company_id', Filament::getTenant()->id)->where('is_active', true)->with('responsible');
                if (! $this->isManager()) {
                    $query->where('responsible_id', auth()->id())->where('can_delegate', true);
                }

                return $query->get()->mapWithKeys(fn ($bpo) => [$bpo->id => 'BPO: '.$bpo->responsible->name])->all();
            });
    }

    private function permissionRepeater(string $name): Repeater
    {
        return Repeater::make($name)->label('Permissões e alcance')->defaultItems(0)->schema([
            Select::make('permission')->label('Ação')->options(PermissionCatalog::labels())->searchable()->required()->distinct()->live(),
            Select::make('scope')->label('Alcance')->options(fn (Get $get) => $get('permission') ? PermissionCatalog::scopeOptions($get('permission')) : [])
                ->required()->default('company'),
        ])->columns(2);
    }

    private function permissionMap(array $rows): array
    {
        return collect($rows)->mapWithKeys(fn ($row) => [$row['permission'] => $row['scope']])->all();
    }

    private function success(): void
    {
        Notification::make()->title('Acessos atualizados')->success()->send();
    }
}
