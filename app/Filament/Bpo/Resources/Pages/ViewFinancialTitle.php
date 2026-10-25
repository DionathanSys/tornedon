<?php

namespace App\Filament\Bpo\Resources\Pages;

use App\Filament\Clusters\Financial\Concerns\RefreshesAccountSummary;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

abstract class ViewFinancialTitle extends ViewRecord
{
    use RefreshesAccountSummary;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
