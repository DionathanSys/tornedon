<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductTaxes\ProductTaxResource;
use Filament\Actions;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditProductTax extends EditRecord
{
    protected static string $resource = ProductTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
