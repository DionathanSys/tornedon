<?php

namespace App\Filament\Management\Resources\Users\Pages;

use App\Enum\User\ManagementRole;
use App\Filament\Management\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Access\UserAccessConfigurationService;
use App\Services\Management\UserAdministrationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var array<int>
     */
    protected array $companyIds = [];

    protected array $accessRows = [];

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var User $actor */
        $actor = Auth::user();
        $role = $data['management_role'] ?? null;

        app(UserAdministrationService::class)->assertCanCreate($actor, $role);

        $data['is_admin'] = $role === ManagementRole::SUPER_ADMIN->value
            || $role === ManagementRole::SUPER_ADMIN;
        $this->companyIds = array_map('intval', $data['companies'] ?? []);
        $this->accessRows = app(UserAccessConfigurationService::class)->validate($data['company_access'] ?? [], $this->companyIds);
        unset($data['companies'], $data['company_access']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return User::query()->create($data);
    }

    protected function afterCreate(): void
    {
        $this->syncCompanies($this->record);
        app(UserAccessConfigurationService::class)->save($this->record, $this->accessRows);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function syncCompanies(User $user): void
    {
        $user->companies()->syncWithPivotValues($this->companyIds, [
            'role' => 'member',
            'is_active' => true,
        ]);
    }
}
