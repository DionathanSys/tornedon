<?php

namespace App\Filament\Bpo\Pages;

use App\Filament\Pages\DreReportPage;

class DreReport extends DreReportPage
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    public function workbenchEyebrow(): string
    {
        return 'RESULTADOS · DRE';
    }

    public function workbenchDescription(): string
    {
        return 'Analise receitas, despesas e resultados por período, com visões projetadas, realizadas e comparativas.';
    }

    protected static string $layout = 'components.bpo.layout';

    protected static ?string $cluster = null;
}
