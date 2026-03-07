<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages\Actions;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class InvoiceRequisitionAction
{
    public static function make(): Action
    {
        return Action::make('invoiceRequisition')
            ->label('Faturar')
            ->icon(Heroicon::DocumentText)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Faturar Requisição')
            ->modalDescription('Tem certeza que deseja faturar esta requisição? Esta ação mudará o status para "Faturada" e não poderá ser desfeita.')
            ->modalSubmitActionLabel('Sim, faturar')
            ->schema([
                Checkbox::make('request_fiscal_document')
                    ->label('Solicitar documento fiscal agora')
                    ->helperText('Se desmarcado, a fatura será criada em aberto para posterior emissão do documento fiscal.')
                    ->default(false),
            ])
            ->visible(fn (Requisition $record): bool => $record->status === Status::CLOSED)
            ->action(function (Requisition $record, array $data): void {
                Log::debug('InvoiceRequisitionAction (Filament): Faturando requisição', [
                    'metodo'                  => __METHOD__ . '@' . __LINE__,
                    'requisition_id'          => $record->id,
                    'user_id'                 => Auth::id(),
                    'request_fiscal_document' => $data['request_fiscal_document'] ?? false,
                ]);

                $service = app(RequisitionService::class);
                $result = $service->invoice($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('InvoiceRequisitionAction (Filament): Erro ao faturar requisição', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $record->id,
                        'error_code'     => $service->getErrorCode(),
                        'message'        => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('InvoiceRequisitionAction (Filament): Requisição faturada com sucesso', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $record->id,
                ]);

                notify::success('Requisição faturada com sucesso.');
            });
    }
}
