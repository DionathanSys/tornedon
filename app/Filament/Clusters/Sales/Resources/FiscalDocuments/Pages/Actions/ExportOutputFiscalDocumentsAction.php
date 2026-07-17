<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions;

use App\Enum\FiscalDocument\OperationType;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportOutputFiscalDocumentsAction
{
    public const NUMERIC_COLUMNS = [
        'amount',
    ];

    public static function make(): Action
    {
        return Action::make('exportOutputFiscalDocuments')
            ->label('Relatório XLS')
            ->icon(Heroicon::DocumentArrowDown)
            ->color('gray')
            ->modalHeading('Gerar relatório de notas de saída em XLS')
            ->modalWidth(Width::Large)
            ->schema(self::getFormSchema())
            ->action(function (array $data): StreamedResponse {
                return self::streamDownload(self::getRecords($data), $data);
            });
    }

    public static function getFormSchema(): array
    {
        return [
            DatePicker::make('start_date')
                ->label('Data inicial')
                ->required()
                ->native(false),
            DatePicker::make('end_date')
                ->label('Data final')
                ->required()
                ->afterOrEqual('start_date')
                ->native(false),
            Select::make('date_column')
                ->label('Data base')
                ->options(self::getDateColumnOptions())
                ->default('issued_at')
                ->required()
                ->native(false),
        ];
    }

    public static function getRecords(array $data): Collection
    {
        $dateColumn = self::normalizeDateColumn($data['date_column'] ?? 'issued_at');
        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);
        $tenantId = Filament::getTenant()?->getKey();

        return FiscalDocument::query()
            ->with(['customer', 'items.service'])
            ->withSum('items as items_total', 'total_price')
            ->where('company_id', $tenantId)
            ->where('operation_type', OperationType::SAIDA->value)
            ->when(
                $dateColumn === 'created_at',
                fn ($query) => $query->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]),
                fn ($query) => $query->whereBetween('issued_at', [$startDate->toDateString(), $endDate->toDateString()]),
            )
            ->orderBy($dateColumn)
            ->orderBy('document_number')
            ->get();
    }

    public static function getColumns(): array
    {
        return [
            'document_number' => ['label' => 'Nro da Nota'],
            'issued_at' => ['label' => 'Data Emissão'],
            'customer_name' => ['label' => 'Cliente'],
            'customer_document' => ['label' => 'CNPJ'],
            'amount' => ['label' => 'Valor'],
            'lc116_service_code' => ['label' => 'Código Serviço LC116'],
        ];
    }

    public static function resolveValue(FiscalDocument $record, string $column): string|float
    {
        return match ($column) {
            'document_number' => (string) ($record->document_number ?? ''),
            'issued_at' => $record->issued_at?->format('d/m/Y') ?? '',
            'customer_name' => (string) ($record->customer?->name ?? ''),
            'customer_document' => self::formatCnpj((string) ($record->customer?->document_number ?? '')),
            'amount' => (float) $record->items_total,
            'lc116_service_code' => self::resolveLc116ServiceCodes($record),
            default => '',
        };
    }

    public static function buildColumns(): array
    {
        return collect(self::getColumns())
            ->map(fn (array $column, string $name): array => ['name' => $name, 'label' => $column['label']])
            ->values()
            ->all();
    }

    public static function buildRows(Collection $records): array
    {
        return $records
            ->map(function (FiscalDocument $record): array {
                $row = [];

                foreach (array_keys(self::getColumns()) as $column) {
                    $value = self::resolveValue($record, $column);
                    $row[$column] = in_array($column, self::NUMERIC_COLUMNS, true)
                        ? number_format((float) $value, 2, ',', '.')
                        : (string) $value;
                }

                return $row;
            })
            ->all();
    }

    public static function buildSummary(Collection $records): array
    {
        return [
            'document_number' => 'Totais',
            'issued_at' => '',
            'customer_name' => '',
            'customer_document' => '',
            'amount' => number_format($records->sum(fn (FiscalDocument $record): float => (float) self::resolveValue($record, 'amount')), 2, ',', '.'),
            'lc116_service_code' => '',
        ];
    }

    public static function buildPeriodDescription(array $data): string
    {
        $dateColumn = self::normalizeDateColumn($data['date_column'] ?? 'issued_at');
        $startDate = Carbon::parse($data['start_date'])->format('d/m/Y');
        $endDate = Carbon::parse($data['end_date'])->format('d/m/Y');

        return self::getDateColumnOptions()[$dateColumn].": {$startDate} a {$endDate}";
    }

    private static function streamDownload(Collection $records, array $data): StreamedResponse
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'output-fiscal-documents-export-');
        $writer = app(Writer::class);
        $writer->openToFile($temporaryFile);
        $totals = array_fill_keys(self::NUMERIC_COLUMNS, 0.0);

        $writer->addRow(new Row(array_map(
            fn (array $column): Cell => Cell::fromValue($column['label']),
            self::getColumns(),
        )));

        foreach ($records as $record) {
            $cells = [];

            foreach (array_keys(self::getColumns()) as $column) {
                $value = self::resolveValue($record, $column);

                if (in_array($column, self::NUMERIC_COLUMNS, true)) {
                    $totals[$column] += (float) $value;
                    $cells[] = Cell::fromValue((float) $value, self::makeNumericStyle());

                    continue;
                }

                $cells[] = Cell::fromValue($value);
            }

            $writer->addRow(new Row($cells));
        }

        $writer->addRow(new Row([
            Cell::fromValue('Totais'),
            Cell::fromValue(''),
            Cell::fromValue(''),
            Cell::fromValue(''),
            Cell::fromValue($totals['amount'], self::makeNumericStyle()),
            Cell::fromValue(''),
        ]));

        $writer->close();

        $fileName = 'notas-saida-'.now()->format('Y-m-d_H-i-s').'.xlsx';

        return response()->streamDownload(function () use ($temporaryFile): void {
            $stream = fopen($temporaryFile, 'rb');

            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }

            @unlink($temporaryFile);
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private static function makeNumericStyle(): Style
    {
        return (new Style)->setFormat('[$-416] #.##0,00');
    }

    private static function getDateColumnOptions(): array
    {
        return [
            'issued_at' => 'Data de emissão',
            'created_at' => 'Criado em',
        ];
    }

    private static function normalizeDateColumn(string $dateColumn): string
    {
        return array_key_exists($dateColumn, self::getDateColumnOptions()) ? $dateColumn : 'issued_at';
    }

    private static function resolveLc116ServiceCodes(FiscalDocument $record): string
    {
        return $record->items
            ->map(fn ($item): ?string => $item->service_code)
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
    }

    private static function formatCnpj(string $document): string
    {
        $digits = preg_replace('/\D+/', '', $document) ?? '';

        if (strlen($digits) !== 14) {
            return $document;
        }

        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $document;
    }
}
