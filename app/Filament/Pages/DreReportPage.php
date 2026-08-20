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
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
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
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Data inicial')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),
                        DatePicker::make('date_to')
                            ->label('Data final')
                            ->native(false)
                            ->displayFormat('d/m/Y')
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
                            ->required(),
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

        foreach ($modelsByCompany as $companyId => $model) {
            $report = $service->generate(
                dreModel: $model,
                companyIds: [(int) $companyId],
                startDate: (string) $filters['date_from'],
                endDate: (string) $filters['date_to'],
                mode: DreMode::from((string) $filters['mode']),
                view: DreView::from((string) $filters['view']),
                costCenterId: $costCenterId,
                resultCenterId: $resultCenterId,
            );

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
                ]);
                $amount = $this->displayAmount($line->amount, $line->displaySign);
                $row['amounts'][$companyId] = $amount;
                $row['total'] = round((float) $row['total'] + $amount, 2);
                $rows->put($key, $row);
            }
        }

        return $rows->values();
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
        return [
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
            'dre_model_id' => DreModel::query()->where('company_id', Filament::getTenant()?->id)->active()->orderByDesc('is_default')->value('id'),
            'mode' => DreMode::COMPETENCE->value,
            'view' => DreView::PROJECTED_AND_REALIZED->value,
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

    public function money(float $amount): string
    {
        return 'R$ '.number_format($amount, 2, ',', '.');
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
