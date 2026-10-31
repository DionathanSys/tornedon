<?php

namespace App\Filament\Bpo\Pages;

use App\Services\Bpo\BpoAccessService;
use App\Services\Bpo\BpoCashFlowService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;

class CashFlow extends Page
{
    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.cash-flow';

    protected static ?string $title = 'Fluxo de caixa e previsão';

    public array $companyIds = [];

    public string $startDate = '';

    public string $endDate = '';

    public string $period = 'next_7_days';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user && app(BpoAccessService::class)->canAccessPanel($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = app(BpoAccessService::class)->companies(Filament::auth()->user());
        $requested = request()->query('company');
        $this->companyIds = $requested && $companies->contains('id', $requested)
            ? [(string) $requested] : $companies->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->setPeriod('next_7_days');
    }

    public function setPeriod(string $period): void
    {
        $today = CarbonImmutable::today();
        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'tomorrow' => [$today->addDay(), $today->addDay()],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'next_week' => [$today->addWeek()->startOfWeek(), $today->addWeek()->endOfWeek()],
            'next_7_days' => [$today, $today->addDays(6)],
            'next_30_days' => [$today, $today->addDays(29)],
            default => [null, null],
        };
        if (! $start) {
            return;
        }
        $this->period = $period;
        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
        $this->resetValidation();
    }

    public function selectAllCompanies(): void
    {
        $this->companyIds = app(BpoAccessService::class)->companies(Filament::auth()->user())
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function updatedStartDate(): void
    {
        $this->period = 'custom';
    }

    public function updatedEndDate(): void
    {
        $this->period = 'custom';
    }

    public function report(): ?array
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(['startDate' => $this->startDate, 'endDate' => $this->endDate, 'companyIds' => $this->companyIds], [
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'companyIds' => ['array'], 'companyIds.*' => ['integer'],
        ], ['endDate.after_or_equal' => 'A data final deve ser igual ou posterior à inicial.'], [
            'startDate' => 'data inicial', 'endDate' => 'data final',
        ]);
        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return null;
        }
        if (CarbonImmutable::parse($this->startDate)->diffInDays(CarbonImmutable::parse($this->endDate)) > 365) {
            $this->addError('endDate', 'Selecione um intervalo de até 366 dias.');

            return null;
        }
        $this->resetValidation();

        return app(BpoCashFlowService::class)->report(Filament::auth()->user(), $this->companyIds, $this->startDate, $this->endDate);
    }

    public function money(int|float $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
