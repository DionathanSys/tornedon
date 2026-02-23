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

final class ApproveQuoteAction
{
    public static function make(): Action
    {
        return Action::make('approveQuote')
            ->label('Aprovar')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aprovar Orçamento')
            ->modalDescription('Tem certeza que deseja aprovar este orçamento? O status mudará para "Aprovado".')
            ->modalSubmitActionLabel('Sim, aprovar')
            ->visible(fn (Quote $record): bool => $record->status === Status::SENT)
            ->action(function (Quote $record): void {
                Log::debug('ApproveQuoteAction (Filament): Aprovando orçamento', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                ]);

                $service = app(QuoteService::class);
                $service->approve($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('ApproveQuoteAction (Filament): Erro ao aprovar orçamento', [
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

                Log::info('ApproveQuoteAction (Filament): Orçamento aprovado com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                ]);

                notify::success('Orçamento aprovado com sucesso.');
            });
    }
}
