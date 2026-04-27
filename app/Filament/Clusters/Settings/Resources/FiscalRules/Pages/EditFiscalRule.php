<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Pages;

use App\Filament\Clusters\Settings\Resources\FiscalRules\FiscalRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFiscalRule extends EditRecord
{
    protected static string $resource = FiscalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
