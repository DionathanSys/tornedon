<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Pages;

use App\Filament\Clusters\Settings\Resources\FiscalRules\FiscalRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditFiscalRule extends EditRecord
{
    protected static string $resource = FiscalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = $this->record->company_id;
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Regra fiscal atualizada com sucesso';
    }
}
