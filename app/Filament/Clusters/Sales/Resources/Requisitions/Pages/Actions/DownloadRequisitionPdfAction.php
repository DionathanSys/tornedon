<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Models\Requisition;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadRequisitionPdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadRequisitionPdf')
            ->label('Download PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('success')
            ->action(function (Requisition $record): StreamedResponse {
                $service = app(RequisitionService::class);
                $pdf     = $service->pdf($record, Auth::id());

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Nao foi possivel gerar o PDF.')->danger()->send();
                    return response()->streamDownload(fn () => null, 'requisicao.pdf');
                }

                $filename = 'requisicao-' . ($record->number ?? $record->id) . '.pdf';

                return response()->streamDownload(function () use ($pdf) {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
