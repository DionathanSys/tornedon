<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\InvoiceProductionOrderAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\DownloadProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\PreviewProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionOrder\ProductionOrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;

    public function getSubheading(): ?string
    {
        return 'OP # ' . ($this->record->production_order_number ?? $this->record->id)
            . ' - ' . $this->record->status->description();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(ProductionOrderResource::getUrl()),
                $this->getSaveFormAction()
                    ->formId('form')
                    ->hiddenLabel()
                    ->icon(Heroicon::Bookmark),
            ])->buttonGroup(),
            ActionGroup::make([
                Action::make('startProduction')
                    ->label('Iniciar')
                    ->icon(Heroicon::Play)
                    ->color('primary')
                    ->visible(fn (): bool => $this->record->status === Status::QUEUED)
                    ->action(function (): void {
                        if (! $this->record->items()->exists()) {
                            notify::warning('Adicione pelo menos um item antes de iniciar a produção.');

                            return;
                        }

                        $service = app(ProductionOrderService::class);

                        if (! $service->start($this->record, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao iniciar produção');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Produção iniciada com sucesso.');
                    }),
                Action::make('updateProgress')
                    ->label('Registrar Produção')
                    ->icon(Heroicon::ClipboardDocumentCheck)
                    ->color('info')
                    ->visible(fn (): bool => $this->record->status === Status::IN_PROGRESS && $this->record->items()->exists())
                    ->modalWidth('7xl')
                    ->fillForm(fn (): array => [
                        'items_progress' => $this->record->items()
                            ->with('product')
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn ($item): array => [
                                'item_id' => $item->id,
                                'item_label' => $item->product?->name ?? $item->description ?? ('Item #' . $item->id),
                                'planned_quantity' => (float) $item->quantity,
                                'quantity_produced' => (float) $item->quantity_produced,
                                'quantity_approved' => (float) $item->quantity_approved,
                                'quantity_rejected' => (float) $item->quantity_rejected,
                                'actual_production_hours' => (float) ($item->actual_production_hours ?? 0),
                                'production_notes' => $item->production_notes,
                            ])
                            ->all(),
                    ])
                    ->schema([
                        Repeater::make('items_progress')
                            ->label('Itens da produção')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                Hidden::make('item_id'),
                                TextInput::make('item_label')
                                    ->label('Item')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(4),
                                TextInput::make('planned_quantity')
                                    ->label('Planejada')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric()
                                    ->columnSpan(2),
                                TextInput::make('quantity_produced')
                                    ->label('Produzida')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('quantity_approved')
                                    ->label('Aprovada')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('quantity_rejected')
                                    ->label('Rejeitada')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('actual_production_hours')
                                    ->label('Horas')
                                    ->numeric()
                                    ->minValue(0)
                                    ->columnSpan(2),
                                Textarea::make('production_notes')
                                    ->label('Notas de produção')
                                    ->rows(2)
                                    ->columnSpan(10),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        $itemsProgress = collect($data['items_progress'] ?? [])
                            ->mapWithKeys(function (array $row): array {
                                $produced = (float) ($row['quantity_produced'] ?? 0);
                                $approved = min((float) ($row['quantity_approved'] ?? 0), $produced);
                                $rejected = min((float) ($row['quantity_rejected'] ?? 0), max(0, $produced - $approved));

                                return [
                                    $row['item_id'] => [
                                        'quantity_produced' => $produced,
                                        'quantity_approved' => $approved,
                                        'quantity_rejected' => $rejected,
                                        'actual_production_hours' => (float) ($row['actual_production_hours'] ?? 0),
                                        'production_notes' => $row['production_notes'] ?? null,
                                    ],
                                ];
                            })
                            ->all();

                        $service = app(ProductionOrderService::class);

                        if (! $service->updateProgress($this->record, $itemsProgress, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao atualizar progresso');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Progresso de produção atualizado com sucesso.');
                    }),
                Action::make('sendToQc')
                    ->label('Enviar para Qualidade')
                    ->icon(Heroicon::CheckBadge)
                    ->color('warning')
                    ->visible(fn (): bool => $this->record->status === Status::IN_PROGRESS)
                    ->action(function (): void {
                        if (! $this->record->items()->where('quantity_produced', '>', 0)->exists()) {
                            notify::warning('Registre quantidade produzida antes de enviar para a qualidade.');

                            return;
                        }

                        $service = app(ProductionOrderService::class);

                        if (! $service->sendToQc($this->record, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao enviar para qualidade');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Ordem enviada para controle de qualidade.');
                    }),
                Action::make('returnToProduction')
                    ->label('Retornar para Produção')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (): bool => $this->record->status === Status::QC_CHECK)
                    ->action(function (): void {
                        $service = app(ProductionOrderService::class);

                        if (! $service->returnToProduction($this->record, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao retornar para produção');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Ordem retornada para produção.');
                    }),
                Action::make('finalizeProduction')
                    ->label('Finalizar')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (): bool => $this->record->status === Status::QC_CHECK)
                    ->schema([
                        Checkbox::make('invoice_after_finalize')
                            ->label('Faturar ao finalizar')
                            ->visible(fn (): bool => $this->record->destination_type === \App\Enum\ProductionOrder\DestinationType::DIRECT_DELIVERY)
                            ->helperText('Uso direto: gera a requisição, encerra para reservar e, se marcado, fatura e abre a fatura.')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        if (! $this->record->items()->where('quantity_approved', '>', 0)->exists()) {
                            notify::warning('Informe ao menos uma quantidade aprovada antes de concluir a produção.');

                            return;
                        }

                        $service = app(ProductionOrderService::class);
                        $result = $service->finalize(
                            $this->record,
                            Auth::id(),
                            (bool) ($data['invoice_after_finalize'] ?? false),
                        );

                        if ($service->hasError() || $result === null) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao finalizar produção');

                            return;
                        }

                        $this->record->refresh();
                        $invoice = $result['invoice'] ?? null;

                        if ($invoice) {
                            notify::success('Produção finalizada e faturada com sucesso.');
                            $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));

                            return;
                        }

                        notify::success(
                            $this->record->destination_type === \App\Enum\ProductionOrder\DestinationType::DIRECT_DELIVERY
                                ? 'Produção finalizada e reservada para venda com sucesso.'
                                : 'Produção finalizada com entrada em estoque registrada.'
                        );
                    }),
                Action::make('viewRequisition')
                    ->label('Abrir Requisição')
                    ->icon(Heroicon::ClipboardDocumentList)
                    ->visible(fn (): bool => filled($this->record->requisition_id))
                    ->url(fn (): ?string => $this->record->requisition_id
                        ? RequisitionResource::getUrl('edit', ['record' => $this->record->requisition_id])
                        : null)
                    ->openUrlInNewTab(),
                InvoiceProductionOrderAction::make(),
                Action::make('viewInvoice')
                    ->label('Abrir Fatura')
                    ->icon(Heroicon::DocumentText)
                    ->visible(fn (): bool => filled($this->record->invoice_id))
                    ->url(fn (): ?string => $this->record->invoice_id
                        ? InvoiceResource::getUrl('edit', ['record' => $this->record->invoice_id])
                        : null)
                    ->openUrlInNewTab(),
                PreviewProductionOrderPdfAction::make(),
                DownloadProductionOrderPdfAction::make(),
                Action::make('cancelProduction')
                    ->label('Cancelar')
                    ->icon(Heroicon::NoSymbol)
                    ->color('danger')
                    ->visible(fn (): bool => in_array($this->record->status, [Status::QUEUED, Status::IN_PROGRESS, Status::QC_CHECK], true))
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $service = app(ProductionOrderService::class);

                        if (! $service->cancel($this->record, Auth::id())) {
                            $this->notifyServiceError($service, 'EditProductionOrder: Erro ao cancelar ordem');

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Ordem de produção cancelada.');
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => blank($this->record->invoice_id) && blank($this->record->requisition_id)),
            ])->button(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();
        
        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    private function notifyServiceError(ProductionOrderService $service, string $logMessage): void
    {
        Log::error($logMessage, [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'production_order_id' => $this->record->id,
            'error_code' => $service->getErrorCode(),
            'message' => $service->getMessage(),
            'errors' => $service->getErrors(),
        ]);

        notify::error(
            message: $service->getMessageUser(),
            errorCode: $service->getErrorCode(),
        );
    }
}
