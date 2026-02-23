<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Notification\NotifyService as notify;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class SendForApprovalQuoteAction
{
    public static function make(): Action
    {
        return Action::make('sendForApproval')
            ->label('Enviar para Aprovação')
            ->icon(Heroicon::PaperAirplane)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Enviar Orçamento para Aprovação')
            ->modalDescription('Tem certeza que deseja enviar este orçamento para aprovação? O status mudará para "Enviado".')
            ->modalSubmitActionLabel('Sim, enviar')
            ->visible(fn (Quote $record): bool => $record->status === Status::DRAFT)
            ->action(function (Quote $record): void {
                Log::debug('SendForApprovalQuoteAction (Filament): Enviando orçamento para aprovação', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                ]);

                $service = app(QuoteService::class);
                $service->sendForApproval($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('SendForApprovalQuoteAction (Filament): Erro ao enviar orçamento para aprovação', [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $record->id,
                        'error_code' => $service->getErrorCode(),
                        'message'    => $service->getMessage(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    return;
                }

                Log::info('SendForApprovalQuoteAction (Filament): Orçamento enviado para aprovação com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                ]);

                notify::success('Orçamento enviado para aprovação com sucesso.');
            });
    }
}
