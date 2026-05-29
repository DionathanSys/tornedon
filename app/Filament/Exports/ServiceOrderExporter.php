<?php

namespace App\Filament\Exports;

use App\Models\ServiceOrder;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class ServiceOrderExporter extends Exporter
{
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
                ->state(fn (ServiceOrder $record): float => (float) $record->services_total_amount)
                ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2, ',', '.')),
            ExportColumn::make('requisition_total_amount')
                ->label('Total Produtos')
                ->state(fn (ServiceOrder $record): float => (float) $record->requisition_total_amount)
                ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2, ',', '.')),
            ExportColumn::make('grand_total_amount')
                ->label('Total Geral')
                ->state(fn (ServiceOrder $record): float => (float) $record->grand_total_amount)
                ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2, ',', '.')),
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
