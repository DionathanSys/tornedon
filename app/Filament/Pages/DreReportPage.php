<?php

namespace App\Filament\Pages;

use App\Enum\Financial\DreDisplaySign;
use App\Enum\Financial\DreMode;
use App\Enum\Financial\DreView;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\DreModel;
use App\Models\ResultCenter;
use App\Services\Financial\Dre\GenerateDreReportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;
use UnitEnum;

class DreReportPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'DRE';

    protected static ?string $title = 'DRE';

    protected static ?string $slug = 'dre';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.dre-report-page';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->defaultFilters());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Filtros')
                    ->collapsible()
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        DateRangePicker::make('date_range')
                            ->label('Período atual')
                            ->format('d/m/Y')
                            ->firstDayOfWeek(0)
                            ->autoApply()
                            ->columnSpan(['default' => 1, 'md' => 2])
                            ->required(),
                        Select::make('dre_model_id')
                            ->label('Modelo DRE')
                            ->options(fn (): array => DreModel::query()
                                ->where('company_id', Filament::getTenant()->id)
                                ->active()
                                ->orderByDesc('is_default')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->default(fn (): ?int => DreModel::query()->where('company_id', Filament::getTenant()->id)->active()->orderByDesc('is_default')->value('id'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),
                        Select::make('mode')
                            ->label('Modo')
                            ->options(DreMode::toSelectArray())
                            ->default(DreMode::COMPETENCE->value)
                            ->native(false)
                            ->required(),
                        Select::make('view')
                            ->label('Visão')
                            ->options(DreView::toSelectArray())
                            ->default(DreView::PROJECTED_AND_REALIZED->value)
                            ->native(false)
                            ->live()
                            ->required(),
                        DateRangePicker::make('comparison_date_range')
                            ->label('Período comparativo')
                            ->format('d/m/Y')
                            ->firstDayOfWeek(0)
                            ->autoApply()
                            ->visible(fn (Get $get): bool => $get('view') === DreView::COMPARATIVE->value)
                            ->required(fn (Get $get): bool => $get('view') === DreView::COMPARATIVE->value)
                            ->columnSpan(['default' => 1, 'md' => 2]),
                        Select::make('comparison_base_view')
                            ->label('Base do comparativo')
                            ->options([
                                DreView::REALIZED->value => DreView::REALIZED->description(),
                                DreView::PROJECTED_AND_REALIZED->value => DreView::PROJECTED_AND_REALIZED->description(),
                            ])
                            ->default(DreView::PROJECTED_AND_REALIZED->value)
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('view') === DreView::COMPARATIVE->value)
                            ->required(fn (Get $get): bool => $get('view') === DreView::COMPARATIVE->value),
                        Select::make('company_ids')
                            ->label('Empresas')
                            ->options(fn (): array => $this->companyOptions())
                            ->default(fn (): array => [Filament::getTenant()->id])
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpanFull(),
                        Select::make('cost_center_id')
                            ->label('Centro de custo')
                            ->options(fn (): array => $this->costCenterOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Select::make('result_center_id')
                            ->label('Centro de resultado')
                            ->options(fn (): array => $this->resultCenterOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function applyFilters(): void
    {
        $this->form->getState();
    }

    public function getRowsProperty(): Collection
    {
        try {
            return $this->buildRows();
        } catch (\Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return collect();
        }
    }

    private function buildRows(): Collection
    {
        $filters = $this->data ?? [];
        if (blank($filters['dre_model_id'] ?? null)) {
            return collect();
        }

        [$startDate, $endDate] = $this->parseDateRange($filters['date_range'] ?? null);
        $isComparative = ($filters['view'] ?? null) === DreView::COMPARATIVE->value;
        [$comparisonStartDate, $comparisonEndDate] = $isComparative
            ? $this->parseDateRange($filters['comparison_date_range'] ?? null)
            : [null, null];
        $reportView = $isComparative
            ? DreView::from((string) ($filters['comparison_base_view'] ?? DreView::PROJECTED_AND_REALIZED->value))
            : DreView::from((string) $filters['view']);

        $baseModel = DreModel::query()
            ->where('company_id', Filament::getTenant()->id)
            ->findOrFail((int) ($filters['dre_model_id'] ?? 0));
        $companyIds = array_values(array_filter(array_map('intval', (array) ($filters['company_ids'] ?? [Filament::getTenant()->id]))));
        $costCenterId = filled($filters['cost_center_id'] ?? null) ? (int) $filters['cost_center_id'] : null;
        $resultCenterId = filled($filters['result_center_id'] ?? null) ? (int) $filters['result_center_id'] : null;

        if (($costCenterId || $resultCenterId) && count($companyIds) > 1) {
            throw new \RuntimeException('Filtros por centro de custo ou resultado estao disponiveis apenas para uma empresa por vez.');
        }

        $modelsByCompany = $this->resolveEquivalentModels($baseModel, $companyIds);
        $service = app(GenerateDreReportService::class);
        $rows = collect();
        $currentRevenueBase = 0.0;

        foreach ($modelsByCompany as $companyId => $model) {
            $report = $service->generate(
                dreModel: $model,
                companyIds: [(int) $companyId],
                startDate: $startDate,
                endDate: $endDate,
                mode: DreMode::from((string) $filters['mode']),
                view: $reportView,
                costCenterId: $costCenterId,
                resultCenterId: $resultCenterId,
            );
            $currentRevenueBase += $report->revenueBase;

            foreach ($this->flattenLines($report->lines) as $line) {
                $key = $line->code ?: (string) $line->lineId;
                $row = $rows->get($key, [
                    'code' => $line->code,
                    'name' => $line->name,
                    'depth' => $line->displayDepth,
                    'is_bold' => $line->isBold,
                    'percentage' => $line->percentage,
                    'amounts' => [],
                    'total' => 0.0,
                    'comparison_amounts' => [],
                    'comparison_total' => 0.0,
                ]);
                $amount = $this->displayAmount($line->amount, $line->displaySign);
                $row['amounts'][$companyId] = $amount;
                $row['total'] = round((float) $row['total'] + $amount, 2);
                $rows->put($key, $row);
            }

            if (! $isComparative) {
                continue;
            }

            $comparisonReport = $service->generate(
                dreModel: $model,
                companyIds: [(int) $companyId],
                startDate: $comparisonStartDate,
                endDate: $comparisonEndDate,
                mode: DreMode::from((string) $filters['mode']),
                view: $reportView,
                costCenterId: $costCenterId,
                resultCenterId: $resultCenterId,
            );

            foreach ($this->flattenLines($comparisonReport->lines) as $line) {
                $key = $line->code ?: (string) $line->lineId;
                $row = $rows->get($key, [
                    'code' => $line->code,
                    'name' => $line->name,
                    'depth' => $line->displayDepth,
                    'is_bold' => $line->isBold,
                    'percentage' => null,
                    'amounts' => [],
                    'total' => 0.0,
                    'comparison_amounts' => [],
                    'comparison_total' => 0.0,
                ]);
                $amount = $this->displayAmount($line->amount, $line->displaySign);
                $row['comparison_amounts'][$companyId] = $amount;
                $row['comparison_total'] = round((float) $row['comparison_total'] + $amount, 2);
                $rows->put($key, $row);
            }
        }

        return $rows->values()->map(function (array $row) use ($isComparative, $currentRevenueBase): array {
            if (! $isComparative) {
                return $row;
            }

            $comparisonTotal = (float) $row['comparison_total'];
            $currentTotal = (float) $row['total'];

            $row['variation_amount'] = round($currentTotal - $comparisonTotal, 2);
            $row['variation_percentage'] = $comparisonTotal == 0.0
                ? ($currentTotal == 0.0 ? 0.0 : null)
                : round((($currentTotal - $comparisonTotal) / abs($comparisonTotal)) * 100, 2);
            $row['percentage'] = $currentRevenueBase > 0
                ? round(($currentTotal / $currentRevenueBase) * 100, 2)
                : null;

            return $row;
        });
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, DreModel>
     */
    private function resolveEquivalentModels(DreModel $baseModel, array $companyIds): array
    {
        $baseModel->refreshStructureHash();
        $models = [];

        foreach ($companyIds as $companyId) {
            $model = (int) $companyId === (int) $baseModel->company_id
                ? $baseModel
                : DreModel::query()
                    ->where('company_id', $companyId)
                    ->where('template_key', $baseModel->template_key)
                    ->where('structure_hash', $baseModel->structure_hash)
                    ->active()
                    ->orderByDesc('is_default')
                    ->first();

            if (! $model) {
                throw new \RuntimeException('As empresas selecionadas não possuem modelos DRE estruturalmente equivalentes.');
            }

            $models[$companyId] = $model;
        }

        return $models;
    }

    private function defaultFilters(): array
    {
        $dateRange = now()->startOfMonth()->format('d/m/Y').' - '.now()->endOfMonth()->format('d/m/Y');

        return [
            'date_range' => $dateRange,
            'comparison_date_range' => $this->previousDateRange($dateRange),
            'dre_model_id' => DreModel::query()->where('company_id', Filament::getTenant()?->id)->active()->orderByDesc('is_default')->value('id'),
            'mode' => DreMode::COMPETENCE->value,
            'view' => DreView::PROJECTED_AND_REALIZED->value,
            'comparison_base_view' => DreView::PROJECTED_AND_REALIZED->value,
            'company_ids' => [Filament::getTenant()?->id],
            'cost_center_id' => null,
            'result_center_id' => null,
        ];
    }

    private function companyOptions(): array
    {
        return auth()->user()?->companies()
            ->orderBy('name')
            ->pluck('name', 'companies.id')
            ->all() ?? [];
    }

    private function costCenterOptions(): array
    {
        $tenantId = Filament::getTenant()?->id;

        return $tenantId ? CostCenter::optionsForCompany((int) $tenantId) : [];
    }

    private function resultCenterOptions(): array
    {
        $tenantId = Filament::getTenant()?->id;

        return $tenantId ? ResultCenter::optionsForCompany((int) $tenantId) : [];
    }

    private function flattenLines(Collection $lines): Collection
    {
        return $lines->flatMap(function ($line): Collection {
            $children = $this->flattenLines($line->children);

            return $line->isVisible
                ? collect([$line])->merge($children)
                : $children;
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseDateRange(mixed $dateRange): array
    {
        if (! is_string($dateRange) || count($dates = explode(' - ', $dateRange)) !== 2) {
            throw new \RuntimeException('Informe um período válido.');
        }

        try {
            $start = Carbon::createFromFormat('!d/m/Y', trim($dates[0]));
            $end = Carbon::createFromFormat('!d/m/Y', trim($dates[1]));

            if ($start->greaterThan($end)) {
                throw new \RuntimeException('Informe um período válido.');
            }

            return [$start->toDateString(), $end->toDateString()];
        } catch (\Throwable) {
            throw new \RuntimeException('Informe um período válido.');
        }
    }

    private function previousDateRange(string $dateRange): string
    {
        [$startDate, $endDate] = $this->parseDateRange($dateRange);
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($start->diffInDays($end));

        return $previousStart->format('d/m/Y').' - '.$previousEnd->format('d/m/Y');
    }

    public function money(float $amount): string
    {
        return 'R$ '.number_format($amount, 2, ',', '.');
    }

    public function percentage(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2, ',', '.').' %';
    }

    public function isComparative(): bool
    {
        return ($this->data['view'] ?? null) === DreView::COMPARATIVE->value;
    }

    public function dateRangeLabel(string $field): string
    {
        return str_replace(' - ', ' a ', (string) ($this->data[$field] ?? ''));
    }

    private function displayAmount(float $amount, string $displaySign): float
    {
        return match (DreDisplaySign::tryFrom($displaySign)) {
            DreDisplaySign::POSITIVE => abs($amount),
            DreDisplaySign::NEGATIVE => -abs($amount),
            default => $amount,
        };
    }

    public function selectedCompanies(): Collection
    {
        return Company::query()
            ->whereIn('id', array_map('intval', (array) ($this->data['company_ids'] ?? [])))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
