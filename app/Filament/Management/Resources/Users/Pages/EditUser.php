<?php

namespace App\Filament\Management\Resources\Users\Pages;

use App\Enum\User\ManagementRole;
use App\Filament\Management\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Management\UserAdministrationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var array<int>
     */
    protected array $companyIds = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->icon(Heroicon::Trash)
                ->visible(fn (User $record): bool => Gate::allows('delete', $record)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['companies'] = $this->record->companies()->pluck('companies.id')->map(fn (mixed $id): int => (int) $id)->all();
        $data['management_role'] = $this->record->managementRole()?->value;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $actor */
        $actor = Auth::user();
        app(UserAdministrationService::class)->assertCanUpdate($actor, $this->record, $data);

        $this->companyIds = array_map('intval', $data['companies'] ?? []);
        unset($data['companies']);

        if (array_key_exists('management_role', $data)) {
            $role = $data['management_role'];
            $data['is_admin'] = $role === ManagementRole::SUPER_ADMIN->value
                || $role === ManagementRole::SUPER_ADMIN;
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncCompanies($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function syncCompanies(User $user): void
    {
        $currentPivots = $user->companies()
            ->withPivot(['role', 'is_active'])
            ->get()
            ->mapWithKeys(fn ($company): array => [
                $company->id => [
                    'role' => $company->pivot->role,
                    'is_active' => (bool) $company->pivot->is_active,
                ],
            ])
            ->all();

        $syncData = [];

        foreach ($this->companyIds as $companyId) {
            $syncData[$companyId] = $currentPivots[$companyId] ?? [
                'role' => 'member',
                'is_active' => true,
            ];
        }

        $user->companies()->sync($syncData);
    }
}
