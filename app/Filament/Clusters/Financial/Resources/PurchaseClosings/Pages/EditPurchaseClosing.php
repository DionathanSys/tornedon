<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages;

use App\Enum\PurchaseClosing\Status;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions\GeneratePurchaseClosingAccountPayableAction;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions\ReopenPurchaseClosingAction;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\PurchaseClosingResource;
use App\Notification\NotifyService as notify;
use App\Services\PurchaseClosing\PurchaseClosingService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditPurchaseClosing extends EditRecord
{
    protected static string $resource = PurchaseClosingResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord()->loadMissing('fiscalDocumentLinks');
        $data['documents'] = $record->fiscalDocumentLinks
            ->map(fn ($link): array => [
                'fiscal_document_id' => $link->fiscal_document_id,
                'discount_amount' => (float) $link->discount_amount,
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(PurchaseClosingResource::getUrl()),
                GeneratePurchaseClosingAccountPayableAction::make(),
                ReopenPurchaseClosingAction::make(),
                DeleteAction::make()
                    ->visible(fn ($record): bool => ! $record->account_payable_id)
                    ->icon(Heroicon::Trash),
            ])->button(),
        ];
    }

    protected function getFormActions(): array
    {
        if ($this->getRecord()->status === Status::CLOSED) {
            return [];
        }

        return parent::getFormActions();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditPurchaseClosing: Iniciando atualização de fechamento de compra', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'purchase_closing_id' => $record->id,
            'data' => $data,
        ]);

        $service = app(PurchaseClosingService::class);
        $updated = $service->update($record, $data, (int) Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'purchase_closing_id' => $record->id,
            ]);

            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $this->halt();
        }

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Fechamento de compra atualizado com sucesso';
    }
}
