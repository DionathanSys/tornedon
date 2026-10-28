<?php

namespace App\Filament\Bpo\Resources\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

abstract class ListFinancialTitles extends ListRecords
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;
    use HasFinancialTabs;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';

    public function workbenchEyebrow(): string
    {
        return static::getResource()::$module === 'account_payables' ? 'DESPESAS · A PAGAR' : 'RECEITAS · A RECEBER';
    }

    public function workbenchDescription(): string
    {
        return 'Acompanhe vencimentos, saldos e baixas. Abra um lançamento para consultar parcelas e documentos.';
    }

    public function getTitle(): string
    {
        return static::getResource()::$module === 'account_payables' ? 'Contas a pagar' : 'Contas a receber';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Novo lançamento')];
    }
}
