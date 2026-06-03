<?php

namespace App\Filament\Management\Resources\Companies\Pages;

use App\Filament\Management\Resources\Companies\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->icon(Heroicon::Trash),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['address'] = array_filter($data['address'] ?? [], fn (mixed $value): bool => filled($value));
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
