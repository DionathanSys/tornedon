<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\Quote\Status;
use App\Models\Quote;
use App\Notification\NotifyService as notify;
use App\Services\Quote\QuoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ConvertToProductionOrderQuoteAction
{
    public static function make(): Action
    {
        return Action::make('convertToProductionOrder')
            ->label('Converter em OP')
            ->icon(Heroicon::Cog6Tooth)
            ->color('warning')
            ->modalHeading('Converter em Ordem de Produção')
            ->modalDescription('Preencha as informações para criar a Ordem de Produção a partir deste orçamento.')
            ->modalSubmitActionLabel('Sim, converter')
            ->form([
                Select::make('priority')
                    ->label('Prioridade')
                    ->options(Priority::toSelectArray())
                    ->default(Priority::NORMAL->value)
                    ->required(),
                Select::make('destination_type')
                    ->label('Tipo de Destino')
                    ->options(DestinationType::toSelectArray())
                    ->default(DestinationType::STOCK->value)
                    ->required(),
                Textarea::make('observations')
                    ->label('Observações')
                    ->rows(3),
            ])
            ->visible(fn (Quote $record): bool => $record->status === Status::APPROVED)
            ->action(function (Quote $record, array $data): void {
                Log::debug('ConvertToProductionOrderQuoteAction (Filament): Convertendo orçamento em OP', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                    'user_id'  => Auth::id(),
                    'data'     => $data,
                ]);

                $service = app(QuoteService::class);
                $service->convertToProductionOrder($record, $data, Auth::id());

                if ($service->hasError()) {
                    Log::error('ConvertToProductionOrderQuoteAction (Filament): Erro ao converter orçamento', [
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

                Log::info('ConvertToProductionOrderQuoteAction (Filament): Ordem de Produção criada com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $record->id,
                ]);

                notify::success('Ordem de Produção criada com sucesso.');
            });
    }
}
