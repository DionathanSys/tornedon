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

final class ReopenQuoteAction
{
    public static function make(): Action
    {
        return Action::make('reopenQuote')
            ->label('Reabrir')
            ->icon(Heroicon::ArrowPath)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Reabrir Orçamento')
            ->modalDescription('Tem certeza que deseja reabrir este orçamento? O status voltará para "Rascunho".')
            ->modalSubmitActionLabel('Sim, reabrir')
            ->visible(fn (Quote $record): bool => in_array($record->status, [Status::REJECTED, Status::EXPIRED, Status::APPROVED]))
            ->action(function (Quote $record): void {
                
                Log::debug('ReopenQuoteAction (Filament): Reabrindo orçamento', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                    'key'      => 'reopen_quote_action',
                ]);

                $service = app(QuoteService::class);
                $service->reopen($record, Auth::id());

                if ($service->hasError()) {
                    Log::error('ReopenQuoteAction (Filament): Erro ao reabrir orçamento', [
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

                Log::info('ReopenQuoteAction (Filament): Orçamento reaberto com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                ]);

                notify::success('Orçamento reaberto com sucesso.');
            });
    }
}
