<?php

namespace App\Filament\Pages;

use App\Enum\Requisition\Status;
use App\Models\Product;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ProductProfitReport extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Lucro por Produto';

    protected static ?string $title = 'Relatório de Lucro por Produto';

    protected static ?string $slug = 'relatorio-lucro-produtos';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.product-profit-report';

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
                    ->columns([
                        'default' => 1,
                        'md' => 4,
                    ])
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Data inicial')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('date_to')
                            ->label('Data final')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Select::make('product_id')
                            ->label('Produto')
                            ->options(fn (): array => $this->productOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Select::make('statuses')
                            ->label('Status da requisição')
                            ->options(Status::toSelectArray())
                            ->multiple()
                            ->native(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function applyFilters(): void
    {
        $this->form->getState();
    }

    public function resetFilters(): void
    {
        $this->form->fill($this->defaultFilters());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRowsProperty(): Collection
    {
        return $this->rawRows()->map(function (object $row): array {
            $soldAmount = round(((float) $row->sold_amount) / 100, 2);
            $grossAmount = round(((float) $row->gross_amount) / 100, 2);
            $discountAmount = round(((float) $row->discount_amount) / 100, 2);
            $costAmount = round(((float) $row->cost_amount) / 100, 2);
            $profitAmount = round($soldAmount - $costAmount, 2);
            $marginPercent = $soldAmount > 0 ? round(($profitAmount / $soldAmount) * 100, 2) : 0.0;

            return [
                'product_id' => (int) $row->product_id,
                'product_code' => (string) ($row->product_code ?? ''),
                'product_name' => (string) $row->product_name,
                'unit_of_measure' => (string) $row->unit_of_measure,
                'sales_count' => (int) $row->sales_count,
                'quantity' => round((float) $row->quantity, 3),
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'sold_amount' => $soldAmount,
                'cost_amount' => $costAmount,
                'profit_amount' => $profitAmount,
                'margin_percent' => $marginPercent,
                'missing_cost_items' => (int) $row->missing_cost_items,
            ];
        });
    }

    public function getSummaryProperty(): array
    {
        $rows = $this->rows;
        $soldAmount = round((float) $rows->sum('sold_amount'), 2);
        $costAmount = round((float) $rows->sum('cost_amount'), 2);
        $profitAmount = round($soldAmount - $costAmount, 2);

        return [
            'products_count' => $rows->count(),
            'sales_count' => (int) $rows->sum('sales_count'),
            'quantity' => round((float) $rows->sum('quantity'), 3),
            'gross_amount' => round((float) $rows->sum('gross_amount'), 2),
            'discount_amount' => round((float) $rows->sum('discount_amount'), 2),
            'sold_amount' => $soldAmount,
            'cost_amount' => $costAmount,
            'profit_amount' => $profitAmount,
            'margin_percent' => $soldAmount > 0 ? round(($profitAmount / $soldAmount) * 100, 2) : 0.0,
            'missing_cost_items' => (int) $rows->sum('missing_cost_items'),
        ];
    }

    public function exportPdf(): StreamedResponse
    {
        $rows = $this->rows;
        $summary = $this->summary;

        $pdfBinary = Pdf::loadView('pdf.product-profit-report', [
            'report' => [
                'title' => 'Relatório de Lucro por Produto',
                'companyName' => Filament::getTenant()?->name ?? config('app.name'),
                'generatedAt' => now()->format('d/m/Y H:i'),
                'generatedBy' => auth()->user()?->name ?? 'Sistema',
                'filters' => $this->resolvedFiltersForDisplay(),
                'rows' => $rows,
                'summary' => $summary,
            ],
        ])->setPaper('a4', 'landscape')->output();

        $fileName = 'lucro-por-produto-' . now()->format('Y-m-d_H-i-s') . '.pdf';

        return response()->streamDownload(function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $fileName, ['Content-Type' => 'application/pdf']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportPdf()),
        ];
    }

    private function defaultFilters(): array
    {
        return [
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'product_id' => null,
            'statuses' => [Status::CLOSED->value, Status::INVOICED->value],
        ];
    }

    private function productOptions(): array
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return [];
        }

        return Product::query()
            ->where('company_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'product_code', 'name'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => trim(($product->product_code ? '[' . $product->product_code . '] ' : '') . $product->name),
            ])
            ->all();
    }

    /**
     * @return Collection<int, object>
     */
    private function rawRows(): Collection
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return collect();
        }

        $filters = $this->data ?? [];
        $statuses = array_values(array_filter((array) ($filters['statuses'] ?? [])));

        return DB::table('requisition_items')
            ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
            ->join('products', 'products.id', '=', 'requisition_items.product_id')
            ->where('requisitions.company_id', $tenantId)
            ->when($statuses !== [], fn ($query) => $query->whereIn('requisitions.status', $statuses))
            ->when($statuses === [], fn ($query) => $query->where('requisitions.status', '!=', Status::CANCELLED->value))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->whereDate('requisitions.sale_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->whereDate('requisitions.sale_date', '<=', $filters['date_to']))
            ->when(filled($filters['product_id'] ?? null), fn ($query) => $query->where('requisition_items.product_id', (int) $filters['product_id']))
            ->groupBy('requisition_items.product_id', 'products.product_code', 'products.name', 'requisition_items.unit_of_measure')
            ->orderByDesc(DB::raw('SUM(requisition_items.total_amount) - SUM(requisition_items.quantity * COALESCE(requisition_items.unit_cost, 0))'))
            ->get([
                'requisition_items.product_id',
                'products.product_code',
                'products.name as product_name',
                'requisition_items.unit_of_measure',
                DB::raw('COUNT(DISTINCT requisitions.id) as sales_count'),
                DB::raw('COALESCE(SUM(requisition_items.quantity), 0) as quantity'),
                DB::raw('COALESCE(SUM(requisition_items.gross_amount), 0) as gross_amount'),
                DB::raw('COALESCE(SUM(requisition_items.discount_amount), 0) as discount_amount'),
                DB::raw('COALESCE(SUM(requisition_items.total_amount), 0) as sold_amount'),
                DB::raw('COALESCE(SUM(requisition_items.quantity * COALESCE(requisition_items.unit_cost, 0)), 0) as cost_amount'),
                DB::raw('SUM(CASE WHEN requisition_items.unit_cost IS NULL OR requisition_items.unit_cost <= 0 THEN 1 ELSE 0 END) as missing_cost_items'),
            ]);
    }

    private function resolvedFiltersForDisplay(): array
    {
        $filters = $this->data ?? [];
        $statuses = array_values(array_filter((array) ($filters['statuses'] ?? [])));
        $productId = $filters['product_id'] ?? null;

        return [
            'Período' => ($this->formatDate($filters['date_from'] ?? null) ?? 'Início') . ' até ' . ($this->formatDate($filters['date_to'] ?? null) ?? 'Hoje'),
            'Produto' => filled($productId) ? ($this->productOptions()[(int) $productId] ?? 'Produto #' . $productId) : 'Todos',
            'Status' => $statuses === []
                ? 'Todos, exceto canceladas'
                : collect($statuses)->map(fn (string $status): string => Status::tryFrom($status)?->description() ?? $status)->implode(', '),
        ];
    }

    private function formatDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        return \Carbon\Carbon::parse($date)->format('d/m/Y');
    }

    public function money(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, ',', '.');
    }

    public function decimal(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, ',', '.');
    }
}
