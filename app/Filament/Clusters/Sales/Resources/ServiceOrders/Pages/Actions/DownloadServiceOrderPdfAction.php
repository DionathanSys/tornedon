<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadServiceOrderPdfAction
{
    public static function make(): Action
    {
        return Action::make('downloadServiceOrderPdf')
            ->label('Download PDF')
            ->icon(Heroicon::ArrowDownTray)
            ->color('primary')
            ->visible(fn (ServiceOrder $record): bool => in_array($record->status, [State::CLOSED, State::INVOICED]))
            ->modalHeading('Baixar PDF da ordem de serviço')
            ->modalSubmitActionLabel('Baixar PDF')
            ->schema([
                Toggle::make('include_service_photos')
                    ->label('Incluir fotos do serviço no PDF')
                    ->helperText('Inclui somente imagens dos anexos do tipo Foto do Serviço ao final do documento.')
                    ->default(false),
            ])
            ->action(function (ServiceOrder $record, array $data): StreamedResponse {
                $service = app(ServiceOrderService::class);
                $pdf = $service->pdf($record, Auth::id(), (bool) ($data['include_service_photos'] ?? false));

                if (! $pdf) {
                    Notification::make()->title($service->getMessage() ?: 'Não foi possivel gerar o PDF.')->danger()->send();

                    return response()->streamDownload(fn () => null, 'ordem-servico.pdf');
                }

                $filename = 'ordem-servico-'.($record->number ?? $record->id).'.pdf';

                return response()->streamDownload(function () use ($pdf) {
                    echo base64_decode($pdf);
                }, $filename, ['Content-Type' => 'application/pdf']);
            });
    }
}
