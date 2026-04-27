<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Pages;

use App\Filament\Clusters\Settings\Resources\FiscalRules\FiscalRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFiscalRules extends ListRecords
{
    protected static string $resource = FiscalRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
