<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
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

final class ExportSelectedServiceOrdersAction
{
    private const NUMERIC_COLUMNS = [
        'services_total_amount',
        'requisition_total_amount',
        'grand_total_amount',
    ];

    public static function make(): BulkAction
    {
        return BulkAction::make('exportSelectedServiceOrders')
            ->label('Exportar Excel')
            ->icon(Heroicon::DocumentArrowDown)
            ->color('gray')
            ->modalHeading('Exportar ordens de serviço selecionadas')
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

                    return response()->streamDownload(fn () => null, 'ordens-servico.xlsx');
                }

                $records->loadMissing([
                    'customer',
                    'equipment',
                    'technician',
                    'invoice',
                    'createdBy',
                    'updatedBy',
                ]);

                return self::streamDownload($records, $selectedColumns);
            });
    }

    private static function streamDownload(Collection $records, array $selectedColumns): StreamedResponse
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'service-orders-export-');
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

        $fileName = 'ordens-servico-' . now()->format('Y-m-d_H-i-s') . '.xlsx';

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
            'number' => ['label' => 'Número', 'default' => true],
            'customer.name' => ['label' => 'Cliente', 'default' => true],
            'order_date' => ['label' => 'Dt. Ordem', 'default' => true],
            'status' => ['label' => 'Status', 'default' => true],
            'priority' => ['label' => 'Prioridade', 'default' => false],
            'type' => ['label' => 'Tipo', 'default' => false],
            'equipment.name' => ['label' => 'Equipamento', 'default' => false],
            'technician.name' => ['label' => 'Técnico', 'default' => false],
            'services_total_amount' => ['label' => 'Total Serviços', 'default' => true],
            'requisition_total_amount' => ['label' => 'Total Produtos', 'default' => true],
            'grand_total_amount' => ['label' => 'Total Geral', 'default' => true],
            'scheduled_date' => ['label' => 'Dt. Agendada', 'default' => false],
            'completion_date' => ['label' => 'Dt. Conclusão', 'default' => false],
            'invoice.invoice_number' => ['label' => 'Fatura', 'default' => true],
            'createdBy.name' => ['label' => 'Criado por', 'default' => false],
            'updatedBy.name' => ['label' => 'Atualizado por', 'default' => false],
            'created_at' => ['label' => 'Criado em', 'default' => false],
            'updated_at' => ['label' => 'Atualizado em', 'default' => false],
        ];
    }

    private static function resolveValue(ServiceOrder $record, string $column): string|float
    {
        return match ($column) {
            'number' => (string) $record->number,
            'customer.name' => (string) ($record->customer?->name ?? ''),
            'order_date' => $record->order_date?->format('d/m/Y') ?? '',
            'status' => $record->status?->description() ?? '',
            'priority' => $record->priority?->description() ?? '',
            'type' => $record->type?->description() ?? '',
            'equipment.name' => (string) ($record->equipment?->name ?? ''),
            'technician.name' => (string) ($record->technician?->name ?? ''),
            'services_total_amount' => (float) $record->services_total_amount,
            'requisition_total_amount' => (float) $record->requisition_total_amount,
            'grand_total_amount' => (float) $record->grand_total_amount,
            'scheduled_date' => $record->scheduled_date?->format('d/m/Y') ?? '',
            'completion_date' => $record->completion_date?->format('d/m/Y') ?? '',
            'invoice.invoice_number' => (string) ($record->invoice?->invoice_number ?? ''),
            'createdBy.name' => (string) ($record->createdBy?->name ?? ''),
            'updatedBy.name' => (string) ($record->updatedBy?->name ?? ''),
            'created_at' => $record->created_at?->format('d/m/Y H:i') ?? '',
            'updated_at' => $record->updated_at?->format('d/m/Y H:i') ?? '',
            default => '',
        };
    }
}
