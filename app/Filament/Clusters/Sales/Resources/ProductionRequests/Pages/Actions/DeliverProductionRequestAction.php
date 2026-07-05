<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\Actions;

use App\Enum\ProductionRequest\Status;
use App\Models\FinancialAccount;
use App\Models\ProductionRequest;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class DeliverProductionRequestAction
{
    public static function make(): Action
    {
        return Action::make('deliver')
            ->label('Encerrar e entregar')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->visible(fn (ProductionRequest $record): bool => $record->status === Status::OPEN)
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
                    ->options(fn (ProductionRequest $record): array => FinancialAccount::optionsForCompany($record->company_id))
                    ->default(fn (ProductionRequest $record): ?int => FinancialAccount::defaultIdForCompany($record->company_id))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false))
                    ->required(fn (Get $get): bool => (bool) ($get('mark_as_received') ?? false)),
            ])
            ->action(function (ProductionRequest $record, array $data): void {
                $service = app(ProductionRequestService::class);
                $delivered = $service->deliver($record, $data, Auth::id());

                if ($service->hasError() || $delivered === null) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                notify::success('Pedido entregue com sucesso.');
            });
    }
}
