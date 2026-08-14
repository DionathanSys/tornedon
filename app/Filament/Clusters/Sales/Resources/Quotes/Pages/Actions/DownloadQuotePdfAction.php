<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Models\Quote;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadQuotePdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadQuotePdf')
            ->label('Baixar PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('primary')
            ->action(function (Quote $record): StreamedResponse {
                $service = app(QuoteService::class);
                $pdf = $service->pdf($record, Auth::id());

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Não foi possível gerar o PDF.')->danger()->send();

                    return response()->streamDownload(fn () => null, 'orcamento.pdf');
                }

                $filename = 'orcamento-'.($record->quote_number ?? $record->id).'.pdf';

                return response()->streamDownload(function () use ($pdf): void {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
