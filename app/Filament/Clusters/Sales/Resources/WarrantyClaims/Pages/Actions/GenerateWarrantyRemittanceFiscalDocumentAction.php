<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\Actions;

use App\Enum\WarrantyClaim\Type as WarrantyClaimType;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\WarrantyClaim;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\WarrantyRemittanceFiscalDocumentService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GenerateWarrantyRemittanceFiscalDocumentAction
{
    public static function make(): Action
    {
        return Action::make('generateWarrantyRemittanceFiscalDocument')
            ->label('Gerar NF de garantia')
            ->icon(Heroicon::DocumentPlus)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Gerar NF-e de remessa em garantia')
            ->modalDescription('Será criada uma NF-e de saída em rascunho para remessa ao fornecedor, com base na origem da garantia.')
            ->visible(fn (WarrantyClaim $record): bool => static::isVisible($record))
            ->action(function (WarrantyClaim $record): void {
                $service = app(WarrantyRemittanceFiscalDocumentService::class);
                $document = $service->generateFromWarrantyClaim($record, (int) Auth::id());

                if ($service->hasError() || $document === null) {
                    Log::warning('GenerateWarrantyRemittanceFiscalDocumentAction: falha ao gerar NF de garantia', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'warranty_claim_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                notify::success(message: 'NF-e de garantia gerada com sucesso.');

                redirect(FiscalDocumentResource::getUrl('edit', ['record' => $document]));
            });
    }

    public static function isVisible(WarrantyClaim $record): bool
    {
        return $record->type === WarrantyClaimType::PRODUCT_SUPPLIER
            && ! $record->hasGeneratedRemittanceFiscalDocument();
    }
}
