<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Notification\NotifyService as notify;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class RejectQuoteAction
{
    public static function make(): Action
    {
        return Action::make('rejectQuote')
            ->label('Rejeitar')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->modalHeading('Rejeitar Orçamento')
            ->modalDescription('Informe o motivo da rejeição deste orçamento.')
            ->modalSubmitActionLabel('Sim, rejeitar')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da Rejeição')
                    ->required()
                    ->rows(3)
                    ->placeholder('Descreva o motivo da rejeição...'),
            ])
            ->visible(fn (Quote $record): bool => in_array($record->status, [Status::DRAFT, Status::SENT, Status::APPROVED]))
            ->action(function (Quote $record, array $data): void {
                Log::debug('RejectQuoteAction (Filament): Rejeitando orçamento', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                ]);

                $service = app(QuoteService::class);
                $service->reject($record, $data['reason'], Auth::id());

                if ($service->hasError()) {
                    Log::error('RejectQuoteAction (Filament): Erro ao rejeitar orçamento', [
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

                Log::info('RejectQuoteAction (Filament): Orçamento rejeitado com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                ]);

                notify::success('Orçamento rejeitado com sucesso.');
            });
    }
}
