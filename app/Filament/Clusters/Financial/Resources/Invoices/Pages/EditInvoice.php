<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions\ConfirmInvoiceAction;
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
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getSubheading(): ?string
    {
        return "Fatura # {$this->record->invoice_number} - {$this->record->status->description()}";
    }

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
                ConfirmInvoiceAction::make()
                    ->size(Size::Small),
                PreviewInvoicePdfAction::make()
                    ->hiddenLabel()
                    ->tooltip('Visualizar PDF')
                    ->size(Size::Small),
                DownloadInvoicePdfAction::make()
                    ->hiddenLabel()
                    ->color('gray')
                    ->tooltip('Baixar PDF')
                    ->size(Size::Small),
                ImportRecordsAction::make()
                    ->size(Size::Small),
                // GenerateFiscalDocumentAction::make(DocumentModel::NFE)
                //     ->color('gray')
                //     ->size(Size::Small),
                // GenerateFiscalDocumentAction::make(DocumentModel::NFSE)
                //     ->color('gray')
                //     ->size(Size::Small),
                DeleteAction::make()
                    ->size(Size::Small)
                    ->icon(Heroicon::Trash)
                    ->hiddenLabel()
                    ->using(function (Model $record): bool {
                        $service = app(InvoiceService::class);
                        $result = $service->delete($record, Auth::id());
                        if ($service->hasError()) {
                            Log::error($service->getMessage(), [
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
            ])->button(),
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
