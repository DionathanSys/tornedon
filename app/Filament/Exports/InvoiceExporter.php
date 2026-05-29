<?php

namespace App\Filament\Exports;

use App\Models\Invoice;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

class InvoiceExporter extends Exporter
{
    private const NUMERIC_COLUMNS = [
        'gross_amount',
        'discount_amount',
        'net_value',
    ];

    private const NUMERIC_FORMAT = '#,##0.00';

    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('invoice_number')
                ->label('Número'),
            ExportColumn::make('customer.name')
                ->label('Cliente'),
            ExportColumn::make('invoice_date')
                ->label('Dt. Fatura')
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y')),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => $state?->description() ?? '-'),
            ExportColumn::make('gross_amount')
                ->label('Valor Bruto')
                ->state(fn (Invoice $record): float => (float) $record->gross_amount),
            ExportColumn::make('discount_amount')
                ->label('Desconto')
                ->state(fn (Invoice $record): float => (float) $record->discount_amount),
            ExportColumn::make('net_value')
                ->label('Valor Líquido')
                ->state(fn (Invoice $record): float => (float) $record->net_value),
            ExportColumn::make('createdBy.name')
                ->label('Criado por')
                ->enabledByDefault(false),
            ExportColumn::make('confirmed_at')
                ->label('Confirmado em')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y H:i')),
            ExportColumn::make('created_at')
                ->label('Criado em')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y H:i')),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'customer',
            'createdBy',
        ]);
    }

    public function getFormats(): array
    {
        return [ExportFormat::Xlsx];
    }

    public function makeXlsxRow(array $values, ?Style $style = null): Row
    {
        $cells = [];
        $columnNames = array_keys($this->columnMap);

        foreach (array_values($values) as $index => $value) {
            $columnName = $columnNames[$index] ?? null;

            if (in_array($columnName, self::NUMERIC_COLUMNS, true)) {
                $numericStyle = (clone ($style ?? new Style()))->setFormat(self::NUMERIC_FORMAT);
                $cells[] = Cell::fromValue((float) $value, $numericStyle);

                continue;
            }

            $cells[] = Cell::fromValue($value, $style);
        }

        return new Row($cells, $style);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $successfulRows = $export->successful_rows;
        $failedRowsCount = $export->getFailedRowsCount();

        $body = 'A exportação das faturas foi concluída';

        if ($successfulRows !== null) {
            $body .= " com {$successfulRows} registro(s) exportado(s)";
        }

        if ($failedRowsCount) {
            $body .= " e {$failedRowsCount} registro(s) com falha";
        }

        return $body . '.';
    }
}
