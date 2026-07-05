<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\FinancialAccount;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditProductionRequest extends EditRecord
{
    protected static string $resource = ProductionRequestResource::class;

    protected function getFormActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return sprintf('Pedido %s - %s', $this->record->number ?? $this->record->id, $this->record->status->description());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['is_manual_counterparty'] = blank($data['customer_id'] ?? null) && filled($data['manual_counterparty_name'] ?? null);
        $data['card_payment_profile_id'] = data_get($data, 'additional_info.card_payment_profile_id');
        $data['payment_date'] = data_get($data, 'additional_info.payment_date');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['additional_info'] = $this->extractAdditionalInfo($data);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(ProductionRequestService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $this->halt();
        }

        return $updated;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->url(ProductionRequestResource::getUrl()),
                $this->getSaveFormAction()
                    ->hiddenLabel()
                    ->icon(Heroicon::Bookmark)
                    ->visible(fn (): bool => $this->record->status === Status::OPEN),
            ])->buttonGroup(),
            ActionGroup::make([
                Action::make('deliver')
                    ->label('Encerrar e entregar')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (): bool => $this->record->status === Status::OPEN)
                    ->schema([
                        DatePicker::make('delivered_at')
                            ->label('Data da entrega')
                            ->default(now())
                            ->required(),
                        Checkbox::make('mark_as_received')
                            ->label('Registrar recebimento agora')
                            ->default(false)
                            ->live(),
                        DatePicker::make('received_at')
                            ->label('Data do recebimento')
                            ->default(now())
                            ->visible(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false))
                            ->required(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false)),
                        Select::make('financial_account_id')
                            ->label('Conta financeira para baixa')
                            ->options(fn (): array => FinancialAccount::optionsForCompany($this->record->company_id))
                            ->default(fn (): ?int => FinancialAccount::defaultIdForCompany($this->record->company_id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false))
                            ->required(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false)),
                    ])
                    ->action(function (array $data): void {
                        $service = app(ProductionRequestService::class);
                        $delivered = $service->deliver($this->record, $data, Auth::id());

                        if ($service->hasError() || $delivered === null) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Pedido entregue com sucesso.');
                    }),
                Action::make('openAccountReceivable')
                    ->label('Abrir Conta a Receber')
                    ->icon(Heroicon::Banknotes)
                    ->visible(fn (): bool => filled($this->record->account_receivable_id))
                    ->url(fn (): ?string => $this->record->account_receivable_id
                        ? AccountReceivableResource::getUrl('edit', ['record' => $this->record->account_receivable_id])
                        : null)
                    ->openUrlInNewTab(),
                Action::make('cancel')
                    ->label('Cancelar')
                    ->icon(Heroicon::NoSymbol)
                    ->color('danger')
                    ->visible(fn (): bool => $this->record->status === Status::OPEN)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $service = app(ProductionRequestService::class);

                        if (! $service->cancel($this->record, Auth::id())) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        $this->record->refresh();
                        notify::success('Pedido cancelado com sucesso.');
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->record->status === Status::OPEN && ! $this->record->items()->exists()),
            ])->button(),
        ];
    }

    private function extractAdditionalInfo(array &$data): array
    {
        $additionalInfo = $data['additional_info'] ?? [];

        $additionalInfo['card_payment_profile_id'] = $data['card_payment_profile_id'] ?? null;
        $additionalInfo['payment_date'] = $data['payment_date'] ?? null;

        unset($data['card_payment_profile_id'], $data['payment_date'], $data['is_manual_counterparty']);

        if (filled($data['customer_id'] ?? null)) {
            $data['manual_counterparty_name'] = null;
        }

        return $additionalInfo;
    }
}
