<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Models\Invoice;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadInvoicePdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadInvoicePdf')
            ->label('Download PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('success')
            ->action(function (Invoice $record): StreamedResponse {
                $service = app(InvoiceService::class);
                $pdf     = $service->pdf($record, Auth::id());

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Nao foi possivel gerar o PDF.')->danger()->send();
                    return response()->streamDownload(fn () => null, 'fatura.pdf');
                }

                $filename = 'fatura-' . ($record->invoice_number ?? $record->id) . '.pdf';

                return response()->streamDownload(function () use ($pdf) {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
