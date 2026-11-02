<?php

namespace App\Filament\Bpo\Pages;

use App\Services\Bpo\BpoPortfolioService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class CompanyOverview extends Dashboard
{
    public string $prioritySearch = '';

    public ?string $priorityFrom = null;

    public ?string $priorityUntil = null;

    public function priorities(): Collection
    {
        $validator = Validator::make(['search' => $this->prioritySearch, 'from' => $this->priorityFrom, 'until' => $this->priorityUntil], [
            'search' => 'string|max:150', 'from' => 'nullable|date_format:Y-m-d', 'until' => ['nullable', 'date_format:Y-m-d', ...($this->priorityFrom ? ['after_or_equal:from'] : [])],
        ]);
        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return collect();
        }
        $this->resetValidation();

        return app(BpoPortfolioService::class)->priorities(Filament::auth()->user(), Filament::getTenant(), trim($this->prioritySearch), $this->priorityFrom, $this->priorityUntil ?: today()->addDays(7)->toDateString());
    }

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.company-overview';

    protected static ?string $title = 'Visão geral da empresa';

    public function summary(): array
    {
        return app(BpoPortfolioService::class)->summary(Filament::auth()->user(), Filament::getTenant());
    }
}
