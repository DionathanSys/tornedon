<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Notification\NotifyService as notify;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
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
            ->schema([
                Grid::make(2)
                    ->schema([
                        Select::make('payment_method')
                            ->label('Método de Pagamento')
                            ->options(array_combine(
                                array_map(fn ($case) => $case->value, PaymentMethod::cases()),
                                array_map(fn ($case) => $case->description(), PaymentMethod::cases())
                            ))
                            ->required()
                            ->columnSpan(1),
                        Select::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->options(array_combine(
                                array_map(fn ($case) => $case->value, PaymentCondition::cases()),
                                array_map(fn ($case) => $case->description(), PaymentCondition::cases())
                            ))
                            ->required()
                            ->columnSpan(1),
                    ]),
            ])
            ->modalHeading('Aprovar Orçamento')
            ->modalDescription('Preencha os dados de pagamento para aprovar o orçamento.')
            ->modalSubmitActionLabel('Aprovar')
            ->modalCancelActionLabel('Cancelar')
            ->visible(fn (Quote $record): bool => in_array($record->status, [Status::DRAFT, Status::SENT]))
            ->action(function (Quote $record, array $data): void {
                Log::debug('ApproveQuoteAction (Filament): Aprovando orçamento', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                ]);

                // Atualiza os dados de pagamento antes de aprovar
                $record->update([
                    'payment_method' => $data['payment_method'],
                    'payment_condition' => $data['payment_condition'],
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
