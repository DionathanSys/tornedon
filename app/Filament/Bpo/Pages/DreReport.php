<?php

namespace App\Filament\Bpo\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Pages\DreReportPage;

class DreReport extends DreReportPage
{
    use HasWorkbenchHeader;

    public function booted(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function workbenchEyebrow(): string
    {
        return 'RESULTADOS · DRE';
    }

    public function workbenchDescription(): string
    {
        return 'Analise resultados por período e consolide empresas autorizadas que possuam modelos DRE equivalentes.';
    }

    protected static string $layout = 'components.bpo.layout';

    protected static ?string $cluster = null;
}
