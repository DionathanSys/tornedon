<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItemOrigin;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\PurchaseReturnFiscalDocumentService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GeneratePurchaseReturnAction
{
    public static function make(): Action
    {
        return Action::make('generatePurchaseReturn')
            ->label('Gerar nota de devolução')
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Gerar NF-e de devolução')
            ->modalDescription('Será criado um documento fiscal de saída em rascunho, vinculado a esta nota de entrada.')
            ->visible(fn(FiscalDocument $record): bool => static::isVisible($record))
            ->action(function (FiscalDocument $record): void {
                $service = app(PurchaseReturnFiscalDocumentService::class);
                $returnDocument = $service->generateFromEntry($record, Auth::id());

                if ($service->hasError() || $returnDocument === null) {
                    Log::warning('GeneratePurchaseReturnAction: falha ao gerar nota de devolução', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'origin_fiscal_document_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser());
                    return;
                }

                notify::success('Nota de devolução gerada com sucesso.');

                redirect(SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument]));
            });
    }

    public static function isVisible(FiscalDocument $record): bool
    {

        $visible = $record->isNfe()
            && $record->operation_type === OperationType::ENTRADA
            && $record->status !== Status::CANCELLED
            && ! $record->canceled
            && ! FiscalDocumentItemOrigin::query()
                ->where('origin_fiscal_document_id', $record->id)
                ->exists();

        return $visible;
    }
}
