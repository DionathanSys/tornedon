<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\OperationalSheetPdfService;
use App\Support\ServiceOrderOperationalSheet;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintOperationalSheetAction
{
    public static function make(): Action
    {
        return Action::make('printOperationalSheet')
            ->label('Imprimir ficha operacional')
            ->icon(Heroicon::Printer)
            ->visible(fn (ServiceOrder $record): bool => ServiceOrderOperationalSheet::enabled($record->company_id))
            ->action(function (ServiceOrder $record): StreamedResponse {
                abort_unless($record->company_id === Filament::getTenant()?->getKey(), 403);

                return app(OperationalSheetPdfService::class)->download($record);
            });
    }
}
