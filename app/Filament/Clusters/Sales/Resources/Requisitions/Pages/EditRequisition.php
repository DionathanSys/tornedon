<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\CancelRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\CloseRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\DownloadRequisitionPdfAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\InvoiceRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\PreviewRequisitionPdfAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\ReopenRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions\ViewInvoiceRequisitionAction;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditRequisition extends EditRecord
{
    protected static string $resource = RequisitionResource::class;

    public function getSubheading(): ?string
    {
        return "Requisição # {$this->record->number} - {$this->record->status->description()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->color(Color::Gray)
                    ->url(RequisitionResource::getUrl()),
                CreateAction::make()
                    ->hiddenLabel()
                    ->tooltip('Nova Requisição')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small)
                    ->mutateDataUsing(function (array $data): array {
                        $tenant = Filament::getTenant();
                        $data['company_id'] = $tenant->id;

                        return $data;
                    })
                    ->using(function (array $data, string $model, CreateAction $action): Model {
                        $service = app(RequisitionService::class);
                        $requisition = $service->create($data, Auth::id());

                        if ($service->hasError() || $requisition === null) {
                            Log::error($service->getMessage(), [
                                'metodo' => __METHOD__ . '@' . __LINE__,
                                'message' => $service->getMessage(),
                                'error_code' => $service->getErrorCode(),
                                'errors' => $service->getErrors(),
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );

                            $action->halt();
                        }

                        Log::info('CreateRequisition: Requisição criada com sucesso', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $requisition->id,
                        ]);

                        return $requisition;
                    })
                    ->successRedirectUrl(fn($record) => RequisitionResource::getUrl('edit', ['record' => $record])),
                CloseRequisitionAction::make()
                    ->size(Size::Small)
                    ->color(Color::Green)
                    ->tooltip('Fechar requisição'),
                PreviewRequisitionPdfAction::make()
                    ->size(Size::Small)
                    ->color(Color::Blue)
                    ->hiddenLabel()
                    ->tooltip('Visualizar PDF da requisição'),
                DownloadRequisitionPdfAction::make()
                    ->size(Size::Small)
                    ->color('info')
                    ->hiddenLabel()
                    ->visible(fn($record) => $record->status === Status::CLOSED)
                    ->tooltip('Baixar PDF da requisição'),
                ReopenRequisitionAction::make()
                    ->size(Size::Small)
                    ->hiddenLabel()
                    ->tooltip('Reabrir requisição'),
                InvoiceRequisitionAction::make()
                    ->size(Size::Small)
                    ->tooltip('Gerar Fatura'),
                ViewInvoiceRequisitionAction::make()
                    ->size(Size::Small)
                    ->hiddenLabel()
                    ->tooltip('Visualizar Fatura'),
                CancelRequisitionAction::make()
                    ->size(Size::Small)
                    ->hiddenLabel()
                    ->tooltip('Cancelar requisição'),
                DeleteAction::make()
                    ->size(Size::Small)
                    ->icon(Heroicon::Trash)
                    ->hiddenLabel()
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando soft delete de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->delete($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao deletar requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição deletada com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
                ForceDeleteAction::make()
                    ->size(Size::Small)
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando force delete de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->forceDelete($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao force delete requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição force deleted com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
                RestoreAction::make()
                    ->size(Size::Small)
                    ->using(function (Model $record): bool {
                        Log::debug('EditRequisition: Iniciando restore de requisição', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->restore($record);

                        if ($service->hasError()) {
                            Log::error('EditRequisition: Erro ao restore requisição', [
                                'metodo'         => __METHOD__ . '@' . __LINE__,
                                'error_code'     => $service->getErrorCode(),
                                'message'        => $service->getMessage(),
                                'requisition_id' => $record->id,
                            ]);

                            notify::error(
                                message: $service->getMessageUser(),
                                errorCode: $service->getErrorCode()
                            );
                            return false;
                        }

                        Log::info('EditRequisition: Requisição restored com sucesso', [
                            'metodo'         => __METHOD__ . '@' . __LINE__,
                            'requisition_id' => $record->id,
                        ]);

                        return $result;
                    }),
            ])->buttonGroup(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Log::debug('EditRequisition: Iniciando atualização de requisição', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $record->id,
            'data'           => $data,
        ]);

        $service = app(RequisitionService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'error_code'     => $service->getErrorCode(),
                'message'        => $service->getMessage(),
                'errors'         => $service->getErrors(),
                'requisition_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('EditRequisition: Requisição atualizada com sucesso', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $updated->id,
        ]);

        return $updated;
    }

    /**
     * Aplica o desconto igualmente entre os itens da requisição.
     */
    public function applyDiscount(): void
    {
        $record = $this->record;
        $discountAmount = (float) str_replace(['.', ','], ['', '.'], $this->data['discount_amount'] ?? '0');

        if ($discountAmount <= 0) {
            notify::warning(
                message: 'Informe um valor de desconto maior que zero.',
                errorCode: 'DISCOUNT_INVALID'
            );
            return;
        }

        // Calcula o valor total dos itens para validação prévia
        $record->load('items');
        $totalItemsValue = $record->items->sum(function ($item) {
            return (float) $item->quantity * (float) $item->unit_price;
        });

        if ($discountAmount > $totalItemsValue) {
            notify::warning(
                message: 'O desconto não pode ser maior que o valor total dos itens (R$ ' . number_format($totalItemsValue, 2, ',', '.') . ').',
                errorCode: 'DISCOUNT_EXCEEDS_ITEMS'
            );
            return;
        }

        $service = app(RequisitionService::class);
        $result = $service->applyDiscount($record, $discountAmount);

        if (! $result) {
            Log::error('EditRequisition: Erro ao aplicar desconto', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $service->getMessage(),
                'requisition_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            return;
        }

        Log::info('EditRequisition: Desconto aplicado com sucesso', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $record->id,
            'discount_amount' => $discountAmount,
        ]);

        notify::success(
            message: 'Desconto aplicado com sucesso aos itens.'
        );

        // redirect($this->getResource()::getUrl('edit', ['record' => $record]));
    }

    /**
     * Remove todos os descontos dos itens da requisição.
     */
    public function clearDiscount(): void
    {
        $record = $this->record;

        $service = app(RequisitionService::class);
        $result = $service->clearDiscount($record);

        if (! $result) {
            Log::error('EditRequisition: Erro ao remover descontos', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'message'        => $service->getMessage(),
                'requisition_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            return;
        }

        Log::info('EditRequisition: Descontos removidos com sucesso', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $record->id,
        ]);

        notify::success(
            message: 'Descontos removidos com sucesso.'
        );

        redirect($this->getResource()::getUrl('edit', ['record' => $record]));
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Requisição atualizada com sucesso';
    }
}
