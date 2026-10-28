<?php

namespace App\Filament\Bpo\Concerns;

use Illuminate\Contracts\View\View;

trait HasWorkbenchHeader
{
    public function getHeader(): ?View
    {
        return view('filament.bpo.partials.workbench-header');
    }

    public function workbenchEyebrow(): string
    {
        return 'OPERAÇÃO FINANCEIRA';
    }

    public function workbenchDescription(): string
    {
        return 'Dados e movimentações da empresa em uma única área de trabalho.';
    }
}
