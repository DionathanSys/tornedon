<?php

namespace App\Filament\Bpo\Pages;

use App\Services\Bpo\BpoAccessService;
use App\Services\Bpo\BpoCashFlowService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;

class CashFlow extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.cash-flow';

    protected static ?string $title = 'Fluxo de caixa e previsão';

    public array $companyIds = [];

    public array $draftCompanyIds = [];

    public array $rangeData = [];

    public string $month = '';

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
            'month' => [$this->selectedMonth()->startOfMonth(), $this->selectedMonth()->endOfMonth()],
            default => [null, null],
        };
        if (! $start) {
            return;
        }
        $this->period = $period;
        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
        $this->month = $start->format('Y-m');
        $this->syncDateRange();
        $this->resetValidation();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('rangeData')->schema([
            DateRangePicker::make('date_range')
                ->label('Período personalizado')
                ->hiddenLabel()
                ->format('d/m/Y')
                ->firstDayOfWeek(1)
                ->alwaysShowCalendar()
                ->autoApply()
                ->maxSpan(['days' => 365])
                ->disableClear()
                ->live()
                ->afterStateUpdated(function (?string $state): void {
                    $dates = explode(' - ', $state ?? '');
                    $validator = Validator::make(['from' => $dates[0] ?? '', 'until' => $dates[1] ?? ''], [
                        'from' => ['required', 'date_format:d/m/Y'],
                        'until' => ['required', 'date_format:d/m/Y', 'after_or_equal:from'],
                    ]);
                    if ($validator->fails()) {
                        return;
                    }
                    $start = CarbonImmutable::createFromFormat('!d/m/Y', $dates[0]);
                    $end = CarbonImmutable::createFromFormat('!d/m/Y', $dates[1]);
                    $this->startDate = $start->toDateString();
                    $this->endDate = $end->toDateString();
                    $this->month = $start->format('Y-m');
                    $this->period = 'custom';
                }),
        ]);
    }

    private function selectedMonth(): CarbonImmutable
    {
        return Validator::make(['month' => $this->month], ['month' => ['required', 'date_format:Y-m']])->passes()
            ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : CarbonImmutable::today()->startOfMonth();
    }

    public function updatedMonth(): void
    {
        $this->setPeriod('month');
    }

    public function moveMonth(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->month = $this->selectedMonth()->addMonthsNoOverflow($direction)->format('Y-m');
        $this->setPeriod('month');
    }

    private function syncDateRange(): void
    {
        if (Validator::make(['from' => $this->startDate, 'until' => $this->endDate], [
            'from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d'],
        ])->fails()) {
            return;
        }
        $this->form->fill(['date_range' => CarbonImmutable::parse($this->startDate)->format('d/m/Y')
            .' - '.CarbonImmutable::parse($this->endDate)->format('d/m/Y')]);
    }

    public function openCompanies(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->draftCompanyIds = $this->companyIds;
        $this->dispatch('open-modal', id: 'cash-flow-companies');
    }

    public function applyCompanies(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = app(BpoAccessService::class)->companies(Filament::auth()->user());
        abort_unless(collect($this->draftCompanyIds)->every(fn ($id) => is_scalar($id) && $companies->contains('id', $id)), 403);
        $this->companyIds = collect($this->draftCompanyIds)->map(fn ($id) => (string) $id)->unique()->values()->all();
        $this->dispatch('close-modal', id: 'cash-flow-companies');
    }

    public function selectAllCompanies(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->draftCompanyIds = app(BpoAccessService::class)->companies(Filament::auth()->user())
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function updatedStartDate(): void
    {
        $this->period = 'custom';
        $this->syncDateRange();
    }

    public function updatedEndDate(): void
    {
        $this->period = 'custom';
        $this->syncDateRange();
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
