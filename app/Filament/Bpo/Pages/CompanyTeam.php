<?php

namespace App\Filament\Bpo\Pages;

class CompanyTeam extends \App\Filament\Pages\CompanyTeam
{
    use \App\Filament\Bpo\Concerns\HasWorkbenchHeader;

    public function workbenchEyebrow(): string
    {
        return 'GESTÃO · EQUIPE E ACESSOS';
    }

    public function workbenchDescription(): string
    {
        return 'Pessoas, papéis e convites organizados em uma única visão dos acessos à empresa.';
    }

    protected static string $layout = 'components.bpo.layout';
}
