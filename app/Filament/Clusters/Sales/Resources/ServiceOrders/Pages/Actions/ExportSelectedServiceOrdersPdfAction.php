<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportSelectedServiceOrdersPdfAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('exportSelectedServiceOrdersPdf')
            ->label('Exportar PDF')
            ->icon(Heroicon::DocumentText)
            ->color('gray')
            ->modalHeading('Exportar ordens de serviço selecionadas em PDF')
            ->modalWidth(Width::ThreeExtraLarge)
            ->deselectRecordsAfterCompletion()
            ->schema([
                CheckboxList::make('columns')
                    ->label('Colunas do relatório')
                    ->options(self::getColumnOptions())
                    ->default(self::getDefaultColumns())
                    ->columns(2)
                    ->required()
                    ->minItems(1)
                    ->bulkToggleable(),
            ])
            ->action(function (Collection $records, array $data): StreamedResponse {
                $selectedColumns = self::normalizeSelectedColumns($data['columns'] ?? []);

                if ($selectedColumns === []) {
                    Notification::make()
                        ->title('Selecione ao menos uma coluna para exportar.')
                        ->danger()
                        ->send();

                    return response()->streamDownload(fn () => null, 'ordens-servico.pdf');
                }

                $records->loadMissing([
                    'customer',
                    'equipment',
                    'technician',
                    'invoice',
                    'createdBy',
                    'updatedBy',
                ]);

                $pdfBinary = Pdf::loadView('pdf.service-order-report', [
                    'report' => [
                        'title' => 'Relatório de Ordens de Serviço',
                        'companyName' => Filament::getTenant()?->name ?? config('app.name'),
                        'generatedAt' => now()->format('d/m/Y H:i'),
                        'generatedBy' => auth()->user()?->name ?? 'Sistema',
                        'columns' => self::buildColumns($selectedColumns),
                        'rows' => self::buildRows($records, $selectedColumns),
                        'summary' => self::buildSummary($records, $selectedColumns),
                    ],
                ])->setPaper('a4', 'landscape')->output();

                $fileName = 'ordens-servico-' . now()->format('Y-m-d_H-i-s') . '.pdf';

                return response()->streamDownload(function () use ($pdfBinary): void {
                    echo $pdfBinary;
                }, $fileName, ['Content-Type' => 'application/pdf']);
            });
    }

    private static function getColumnOptions(): array
    {
        return collect(ExportSelectedServiceOrdersAction::getColumns())
            ->mapWithKeys(fn (array $column, string $name): array => [$name => $column['label']])
            ->all();
    }

    private static function getDefaultColumns(): array
    {
        return collect(ExportSelectedServiceOrdersAction::getColumns())
            ->filter(fn (array $column): bool => $column['default'])
            ->keys()
            ->all();
    }

    private static function normalizeSelectedColumns(array $selectedColumns): array
    {
        return array_values(array_intersect(array_keys(ExportSelectedServiceOrdersAction::getColumns()), $selectedColumns));
    }

    private static function buildColumns(array $selectedColumns): array
    {
        $allColumns = ExportSelectedServiceOrdersAction::getColumns();

        return array_map(
            fn (string $column): array => ['name' => $column, 'label' => $allColumns[$column]['label']],
            $selectedColumns,
        );
    }

    private static function buildRows(Collection $records, array $selectedColumns): array
    {
        return $records
            ->map(function ($record) use ($selectedColumns): array {
                $row = [];

                foreach ($selectedColumns as $column) {
                    $value = ExportSelectedServiceOrdersAction::resolveValue($record, $column);
                    $row[$column] = in_array($column, ExportSelectedServiceOrdersAction::NUMERIC_COLUMNS, true)
                        ? number_format((float) $value, 2, ',', '.')
                        : (string) $value;
                }

                return $row;
            })
            ->all();
    }

    private static function buildSummary(Collection $records, array $selectedColumns): array
    {
        $summary = [];

        foreach ($selectedColumns as $index => $column) {
            if (in_array($column, ExportSelectedServiceOrdersAction::NUMERIC_COLUMNS, true)) {
                $summary[$column] = number_format(
                    $records->sum(fn ($record): float => (float) ExportSelectedServiceOrdersAction::resolveValue($record, $column)),
                    2,
                    ',',
                    '.',
                );

                continue;
            }

            $summary[$column] = $index === 0 ? 'Totais' : '';
        }

        return $summary;
    }
}
