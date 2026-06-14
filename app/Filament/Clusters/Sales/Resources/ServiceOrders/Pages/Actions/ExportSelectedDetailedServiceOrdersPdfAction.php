<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportSelectedDetailedServiceOrdersPdfAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('exportSelectedDetailedServiceOrdersPdf')
            ->label('Exportar PDF detalhado')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): StreamedResponse {
                if ($records->isEmpty()) {
                    Notification::make()
                        ->title('Selecione ao menos uma ordem de serviço para exportar.')
                        ->danger()
                        ->send();

                    return response()->streamDownload(fn () => null, 'ordens-servico-detalhadas.pdf');
                }

                $records->loadMissing([
                    'customer',
                    'company',
                    'equipment.owner',
                    'technician',
                    'supervisor',
                    'salesperson',
                    'items.service',
                    'requisition.items.product',
                ]);

                $formatter = app(ServiceOrderPdfDataFormatter::class);

                $documents = $records
                    ->map(fn ($record): array => [
                        'record' => $record,
                        'pdfData' => $formatter->format($record),
                    ])
                    ->values()
                    ->all();

                $pdfBinary = Pdf::loadView('pdf.service-order-batch', [
                    'documents' => $documents,
                ])->setPaper('a4')->output();

                $fileName = 'ordens-servico-detalhadas-' . now()->format('Y-m-d_H-i-s') . '.pdf';

                return response()->streamDownload(function () use ($pdfBinary): void {
                    echo $pdfBinary;
                }, $fileName, ['Content-Type' => 'application/pdf']);
            });
    }
}
