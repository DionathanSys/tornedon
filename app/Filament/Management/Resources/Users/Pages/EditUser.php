<?php

namespace App\Filament\Management\Resources\Users\Pages;

use App\Filament\Management\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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
            DeleteAction::make()->icon(Heroicon::Trash),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['companies'] = $this->record->companies()->pluck('companies.id')->map(fn (mixed $id): int => (int) $id)->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->companyIds = array_map('intval', $data['companies'] ?? []);
        unset($data['companies']);

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
