<?php

namespace App\Filament\Bpo\Pages;

use App\Services\Bpo\BpoPortfolioService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

class CompanyOverview extends Dashboard
{
    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.company-overview';

    protected static ?string $title = 'Visão geral da empresa';

    public function summary(): array
    {
        return app(BpoPortfolioService::class)->summary(Filament::auth()->user(), Filament::getTenant());
    }
}
