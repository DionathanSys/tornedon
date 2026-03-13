<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions;

use App\Models\ProductionOrder;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadProductionOrderPdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadProductionOrderPdf')
            ->label('Download PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('success')
            ->action(function (ProductionOrder $record): StreamedResponse {
                $service = app(ProductionOrderService::class);
                $pdf     = $service->pdf($record, Auth::id());

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Nao foi possivel gerar o PDF.')->danger()->send();
                    return response()->streamDownload(fn () => null, 'ordem-producao.pdf');
                }

                $filename = 'ordem-producao-' . ($record->production_order_number ?? $record->id) . '.pdf';

                return response()->streamDownload(function () use ($pdf) {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
