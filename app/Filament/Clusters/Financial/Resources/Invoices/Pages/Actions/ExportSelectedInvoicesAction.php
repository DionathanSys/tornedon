<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Models\Invoice;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportSelectedInvoicesAction
{
    private const NUMERIC_COLUMNS = [
        'gross_amount',
        'discount_amount',
        'net_value',
    ];

    public static function make(): BulkAction
    {
        return BulkAction::make('exportSelectedInvoices')
            ->label('Exportar Excel')
            ->icon(Heroicon::DocumentArrowDown)
            ->color('gray')
            ->modalHeading('Exportar faturas selecionadas')
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

                    return response()->streamDownload(fn () => null, 'faturas.xlsx');
                }

                $records->loadMissing([
                    'customer',
                    'createdBy',
                ]);

                return self::streamDownload($records, $selectedColumns);
            });
    }

    private static function streamDownload(Collection $records, array $selectedColumns): StreamedResponse
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'invoices-export-');
        $writer = app(Writer::class);
        $writer->openToFile($temporaryFile);

        $writer->addRow(new Row(array_map(
            fn (string $column): Cell => Cell::fromValue(self::getColumns()[$column]['label']),
            $selectedColumns,
        )));

        foreach ($records as $record) {
            $cells = [];

            foreach ($selectedColumns as $column) {
                $value = self::resolveValue($record, $column);

                if (in_array($column, self::NUMERIC_COLUMNS, true)) {
                    $cells[] = Cell::fromValue((float) $value, self::makeNumericStyle());

                    continue;
                }

                $cells[] = Cell::fromValue($value);
            }

            $writer->addRow(new Row($cells));
        }

        $writer->close();

        $fileName = 'faturas-' . now()->format('Y-m-d_H-i-s') . '.xlsx';

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
        return (new Style())->setFormat('[$-416] #.##0,00');
    }

    private static function getColumnOptions(): array
    {
        return collect(self::getColumns())
            ->mapWithKeys(fn (array $column, string $name): array => [$name => $column['label']])
            ->all();
    }

    private static function getDefaultColumns(): array
    {
        return collect(self::getColumns())
            ->filter(fn (array $column): bool => $column['default'])
            ->keys()
            ->all();
    }

    private static function normalizeSelectedColumns(array $selectedColumns): array
    {
        return array_values(array_intersect(array_keys(self::getColumns()), $selectedColumns));
    }

    private static function getColumns(): array
    {
        return [
            'invoice_number' => ['label' => 'Número', 'default' => true],
            'customer.name' => ['label' => 'Cliente', 'default' => true],
            'invoice_date' => ['label' => 'Dt. Fatura', 'default' => true],
            'status' => ['label' => 'Status', 'default' => true],
            'gross_amount' => ['label' => 'Valor Bruto', 'default' => true],
            'discount_amount' => ['label' => 'Desconto', 'default' => true],
            'net_value' => ['label' => 'Valor Líquido', 'default' => true],
            'createdBy.name' => ['label' => 'Criado por', 'default' => false],
            'confirmed_at' => ['label' => 'Confirmado em', 'default' => false],
            'created_at' => ['label' => 'Criado em', 'default' => false],
        ];
    }

    private static function resolveValue(Invoice $record, string $column): string|float
    {
        return match ($column) {
            'invoice_number' => (string) $record->invoice_number,
            'customer.name' => (string) ($record->customer?->name ?? ''),
            'invoice_date' => $record->invoice_date?->format('d/m/Y') ?? '',
            'status' => $record->status?->description() ?? '-',
            'gross_amount' => (float) $record->gross_amount,
            'discount_amount' => (float) $record->discount_amount,
            'net_value' => (float) $record->net_value,
            'createdBy.name' => (string) ($record->createdBy?->name ?? ''),
            'confirmed_at' => $record->confirmed_at?->format('d/m/Y H:i') ?? '',
            'created_at' => $record->created_at?->format('d/m/Y H:i') ?? '',
            default => '',
        };
    }
}
