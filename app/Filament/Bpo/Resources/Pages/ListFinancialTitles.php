<?php

namespace App\Filament\Bpo\Resources\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

abstract class ListFinancialTitles extends ListRecords
{
    use HasFinancialTabs;

    protected static string $layout = 'components.bpo.layout';

    public function getTitle(): string
    {
        return static::getResource()::$module === 'account_payables' ? 'Contas a pagar' : 'Contas a receber';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo lançamento')];
    }
}
