<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\ConfirmEntryAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\CreateServiceOrderFromEntryAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions\GeneratePurchaseReturnAction;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\FiscalDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditFiscalDocument extends EditRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ConfirmEntryAction::make(),
                CreateServiceOrderFromEntryAction::make(),
                GeneratePurchaseReturnAction::make(),
                Action::make('viewLinkedReturnFiscalDocument')
                    ->label('Ver nota vinculada')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn ($record): bool => $record->linkedReturnFiscalDocument() !== null)
                    ->url(fn ($record): ?string => ($linkedRecord = $record->linkedReturnFiscalDocument())
                        ? SalesFiscalDocumentResource::getUrl('edit', ['record' => $linkedRecord])
                        : null),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditFiscalDocument: Iniciando exclusão de nota de entrada', [
                            'metodo' => __METHOD__.'@'.__LINE__,
                            'fiscal_document_id' => $record->id,
                        ]);

                        $service = app(FiscalDocumentService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditFiscalDocument: Erro ao deletar nota de entrada', [
                                'metodo' => __METHOD__.'@'.__LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message' => $service->getMessage(),
                                'fiscal_document_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            return false;
                        }

                        Log::info('EditFiscalDocument: Nota de entrada deletada com sucesso', [
                            'metodo' => __METHOD__.'@'.__LINE__,
                            'fiscal_document_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['is_final_consumer'] = true;

        if (($data['document_type'] ?? $this->getRecord()->document_type?->value) === DocumentModel::NFE->value) {
            $data['buyer_presence_indicator'] ??= $this->getRecord()->buyer_presence_indicator?->value
                ?? BuyerPresenceIndicator::OUTROS->value;

            $data['freight_data'] ??= $this->getRecord()->freight_data
                ?? ['modalidade_frete' => FreightModality::SEM_FRETE->value];

            $data['freight_data']['modalidade_frete'] ??= data_get(
                $this->getRecord()->freight_data,
                'modalidade_frete',
                FreightModality::SEM_FRETE->value,
            );
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditFiscalDocument: Iniciando atualização de nota de entrada', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'fiscal_document_id' => $record->id,
            'data' => $data,
        ]);

        $service = app(FiscalDocumentService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'fiscal_document_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditFiscalDocument: Nota de entrada atualizada com sucesso', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'fiscal_document_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Nota de entrada atualizada com sucesso';
    }

    protected function getFormActions(): array
    {
        if ($this->getRecord()->isImportedFromDfe()) {
            return [];
        }

        return parent::getFormActions();
    }
}
