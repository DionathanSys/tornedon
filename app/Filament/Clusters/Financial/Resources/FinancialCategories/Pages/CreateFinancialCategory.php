<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories\Pages;

use App\Filament\Clusters\Financial\Resources\FinancialCategories\FinancialCategoryResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFinancialCategory extends CreateRecord
{
    protected static string $resource = FinancialCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
