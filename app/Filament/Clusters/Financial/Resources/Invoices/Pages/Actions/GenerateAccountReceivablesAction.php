<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\CardPaymentProfile;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class GenerateAccountReceivablesAction
{
    public static function make(): Action
    {
        return Action::make('generateAccountReceivables')
            ->label('Gerar contas')
            ->icon(Heroicon::Banknotes)
            ->color('primary')
            ->modalHeading('Gerar contas a receber')
            ->modalDescription('Use esta acao quando a fatura estiver confirmada e as contas a receber ainda nao tiverem sido geradas.')
            ->visible(fn (Invoice $record): bool => $record->confirmed && ! $record->canceled && ! $record->accountReceivables()->exists())
            ->schema([
                Select::make('payment_method')
                    ->label('Forma de Pagamento')
                    ->options(Method::toSelectArray())
                    ->default(fn (Invoice $record): ?string => $record->payment_method?->value)
                    ->native(false)
                    ->live()
                    ->required(),

                Select::make('card_payment_profile_id')
                    ->label('Perfil de Recebimento no Cartao')
                    ->options(fn (): array => CardPaymentProfile::query()
                        ->where('company_id', Filament::getTenant()?->id)
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                    ->required(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                    ->helperText('O vencimento financeiro seguira o prazo D+X configurado neste perfil.'),

                DatePicker::make('payment_date')
                    ->label('Data da Venda/Pagamento no Cartao')
                    ->default(fn (Invoice $record): ?string => $record->invoice_date?->toDateString() ?? now()->toDateString())
                    ->visible(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value)
                    ->required(fn (Get $get): bool => (string) $get('payment_method') === Method::CREDIT_CARD->value),

                Select::make('payment_condition')
                    ->label('Condicao de Pagamento')
                    ->options(Condition::toGroupedSelectArray())
                    ->default(fn (Invoice $record): ?string => $record->payment_condition?->value)
                    ->native(false)
                    ->required(fn (Get $get): bool => (string) $get('payment_method') !== Method::CREDIT_CARD->value)
                    ->helperText('Em cartao, informe apenas se precisar parcelar comercialmente. O primeiro vencimento usara o prazo da operadora.'),
            ])
            ->action(function (Invoice $record, array $data, EditInvoice $livewire): void {
                $service = app(InvoiceService::class);
                $result = $service->generateAccountReceivables($record, $data, Auth::id());

                if ($service->hasError() || $result === null) {
                    Log::error('GenerateAccountReceivablesAction: erro ao gerar contas a receber', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                        'data' => $data,
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode(),
                    );

                    return;
                }

                notify::success('Contas a receber geradas com sucesso.');
                $livewire->refreshInvoiceState();
                $livewire->dispatch('invoice-account-receivables-refresh');
            });
    }
}
