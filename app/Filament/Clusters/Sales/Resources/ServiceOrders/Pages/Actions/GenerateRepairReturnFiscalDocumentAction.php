<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\RepairReturnFiscalDocumentService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GenerateRepairReturnFiscalDocumentAction
{
    public static function make(): Action
    {
        return Action::make('generateRepairReturnFiscalDocument')
            ->label('Gerar nota de retorno')
            ->icon(Heroicon::DocumentPlus)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Gerar NF-e de retorno')
            ->modalDescription('Será criada uma NF-e de saída vinculando a nota de remessa associada a esta ordem de serviço.')
            ->visible(fn (ServiceOrder $record): bool => static::isVisible($record))
            ->action(function (ServiceOrder $record): void {
                $service = app(RepairReturnFiscalDocumentService::class);
                $returnDocument = $service->generateFromServiceOrder($record, Auth::id());

                if ($service->hasError() || $returnDocument === null) {
                    Log::warning('GenerateRepairReturnFiscalDocumentAction: falha ao gerar nota de retorno', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'service_order_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser());

                    return;
                }

                notify::success('Nota de retorno gerada com sucesso.');

                redirect(SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument]));
            });
    }

    public static function isVisible(ServiceOrder $record): bool
    {
        return $record->remittanceAssets()->exists()
            && $record->linkedReturnFiscalDocument() === null;
    }
}
