<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages;

use App\Filament\Clusters\Financial\Actions\IgnoreFinancialAction;
use App\Filament\Clusters\Financial\Concerns\RefreshesAccountSummary;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Financial\Resources\Components\AccountSummary;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAccountReceivable extends ViewRecord
{
    use RefreshesAccountSummary;

    protected static string $resource = AccountReceivableResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AccountSummary::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), IgnoreFinancialAction::make(), IgnoreFinancialAction::make(restore: true)];
    }
}
