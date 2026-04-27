<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Pages;

use App\Filament\Clusters\Settings\Resources\FiscalRules\FiscalRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFiscalRule extends CreateRecord
{
    protected static string $resource = FiscalRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();

        $data['company_id'] = $tenant?->id;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Regra fiscal criada com sucesso';
    }
}
