<?php

namespace App\Filament\Exports;

use App\Models\ServiceOrder;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

class ServiceOrderExporter extends Exporter
{
    private const NUMERIC_COLUMNS = [
        'services_total_amount',
        'requisition_total_amount',
        'grand_total_amount',
    ];

    protected static ?string $model = ServiceOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')
                ->label('Número'),
            ExportColumn::make('customer.name')
                ->label('Cliente'),
            ExportColumn::make('order_date')
                ->label('Dt. Ordem')
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y')),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): ?string => $state?->description()),
            ExportColumn::make('priority')
                ->label('Prioridade')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->description()),
            ExportColumn::make('type')
                ->label('Tipo')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->description()),
            ExportColumn::make('equipment.name')
                ->label('Equipamento')
                ->enabledByDefault(false),
            ExportColumn::make('technician.name')
                ->label('Técnico')
                ->enabledByDefault(false),
            ExportColumn::make('services_total_amount')
                ->label('Total Serviços')
                ->state(fn (ServiceOrder $record): float => (float) $record->services_total_amount),
            ExportColumn::make('requisition_total_amount')
                ->label('Total Produtos')
                ->state(fn (ServiceOrder $record): float => (float) $record->requisition_total_amount),
            ExportColumn::make('grand_total_amount')
                ->label('Total Geral')
                ->state(fn (ServiceOrder $record): float => (float) $record->grand_total_amount),
            ExportColumn::make('scheduled_date')
                ->label('Dt. Agendada')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y')),
            ExportColumn::make('completion_date')
                ->label('Dt. Conclusão')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y')),
            ExportColumn::make('invoice.invoice_number')
                ->label('Fatura'),
            ExportColumn::make('createdBy.name')
                ->label('Criado por')
                ->enabledByDefault(false),
            ExportColumn::make('updatedBy.name')
                ->label('Atualizado por')
                ->enabledByDefault(false),
            ExportColumn::make('created_at')
                ->label('Criado em')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y H:i')),
            ExportColumn::make('updated_at')
                ->label('Atualizado em')
                ->enabledByDefault(false)
                ->formatStateUsing(fn ($state): ?string => $state?->format('d/m/Y H:i')),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'customer',
            'equipment',
            'technician',
            'invoice',
            'createdBy',
            'updatedBy',
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
                $numericStyle = (clone ($style ?? new Style()))->setFormat('0.00');
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

        $body = 'A exportação das ordens de serviço foi concluída';

        if ($successfulRows !== null) {
            $body .= " com {$successfulRows} registro(s) exportado(s)";
        }

        if ($failedRowsCount) {
            $body .= " e {$failedRowsCount} registro(s) com falha";
        }

        return $body . '.';
    }
}
