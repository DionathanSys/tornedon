<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadServiceOrderPdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadServiceOrderPdf')
            ->label('PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('primary')
            ->visible(fn (ServiceOrder $record): bool => in_array($record->status, [State::CLOSED, State::INVOICED]))
            ->action(function (ServiceOrder $record): StreamedResponse {
                $service = app(ServiceOrderService::class);
                $pdf     = $service->pdf($record, Auth::id());

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Não foi possivel gerar o PDF.')->danger()->send();
                    return response()->streamDownload(fn () => null, 'ordem-servico.pdf');
                }

                $filename = 'ordem-servico-' . ($record->number ?? $record->id) . '.pdf';

                return response()->streamDownload(function () use ($pdf) {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
