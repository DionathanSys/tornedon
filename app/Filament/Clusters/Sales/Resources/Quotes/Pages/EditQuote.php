<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages;

use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ApproveQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ConvertToProductionOrderQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\RejectQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ReopenQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\SendForApprovalQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ViewLinkedProductionOrderAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ViewLinkedRequisitionsAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ViewLinkedServiceOrdersAction;
use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use App\Notification\NotifyService as notify;
use App\Services\Quote\QuoteService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewLinkedRequisitionsAction::make(),
            ViewLinkedServiceOrdersAction::make(),
            ViewLinkedProductionOrderAction::make(),
            ActionGroup::make([
                // SendForApprovalQuoteAction::make(),
                ApproveQuoteAction::make(),
                RejectQuoteAction::make(),
                ReopenQuoteAction::make(),
                ConvertToProductionOrderQuoteAction::make(),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        Log::debug('EditQuote: Iniciando soft delete de orçamento', [
                            'metodo'   => __METHOD__ . '@' . __LINE__,
                            'quote_id' => $record->id,
                        ]);

                        $service = app(QuoteService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditQuote: Erro ao deletar orçamento', [
                                'metodo'     => __METHOD__ . '@' . __LINE__,
                                'error_code' => $service->getErrorCode(),
                                'message'    => $service->getMessage(),
                                'quote_id'   => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditQuote: Orçamento deletado com sucesso', [
                            'metodo'   => __METHOD__ . '@' . __LINE__,
                            'quote_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->button(),
        ];
    }

    protected function getFormActions(): array
    {
        if (! $this->record->state()->canEdit()) {          
            return [];
        }

        return parent::getFormActions();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditQuote: Iniciando atualização de orçamento', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $record->id,
            'data'     => $data,
        ]);

        $service = app(QuoteService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message'    => $service->getMessage(),
                'errors'     => $service->getErrors(),
                'quote_id'   => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditQuote: Orçamento atualizado com sucesso', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $updated->id,
        ]);

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Orçamento atualizado com sucesso';
    }
}
