<?php

namespace App\Filament\Management\Resources\Users\Pages;

use App\Filament\Management\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var array<int>
     */
    protected array $companyIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->companyIds = array_map('intval', $data['companies'] ?? []);
        unset($data['companies']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return User::query()->create($data);
    }

    protected function afterCreate(): void
    {
        $this->syncCompanies($this->record);
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
