<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\DownloadInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\GenerateFiscalDocumentAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ImportRecordsAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\PreviewInvoicePdfAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ViewLinkedFiscalDocumentsAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ViewLinkedProductionOrdersAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ViewLinkedRequisitionsAction;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ViewLinkedServiceOrdersAction;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ViewLinkedFiscalDocumentsAction::make()
                    ->size(Size::Small),
                ViewLinkedRequisitionsAction::make()
                    ->size(Size::Small),
                ViewLinkedServiceOrdersAction::make()
                    ->size(Size::Small),
                ViewLinkedProductionOrdersAction::make()
                    ->size(Size::Small),
            ])->buttonGroup(),
            ActionGroup::make([
                PreviewInvoicePdfAction::make()
                    ->size(Size::Small),
                DownloadInvoicePdfAction::make()
                    ->size(Size::Small),
                ImportRecordsAction::make()
                    ->size(Size::Small),
                GenerateFiscalDocumentAction::make()
                    ->size(Size::Small),
                DeleteAction::make()
                    ->size(Size::Small)
                    ->using(function (Model $record): bool {
                        Log::debug('EditInvoice: Iniciando exclusão de fatura', [
                            'metodo'     => __METHOD__ . '@' . __LINE__,
                            'invoice_id' => $record->id,
                        ]);

                        $service = app(InvoiceService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditInvoice: Erro ao deletar fatura', [
                                'metodo'     => __METHOD__ . '@' . __LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message'    => $service->getMessage(),
                                'invoice_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditInvoice: Fatura deletada com sucesso', [
                            'metodo'     => __METHOD__ . '@' . __LINE__,
                            'invoice_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->buttonGroup(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditInvoice: Iniciando atualização de fatura', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'invoice_id' => $record->id,
            'data'       => $data,
        ]);

        $service = app(InvoiceService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message'    => $service->getMessage(),
                'errors'     => $service->getErrors(),
                'invoice_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditInvoice: Fatura atualizada com sucesso', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'invoice_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Fatura atualizada com sucesso';
    }
}
