<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\Actions;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\PurchaseClosing\Status;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Models\FinancialCategory;
use App\Models\PurchaseClosing;
use App\Notification\NotifyService as notify;
use App\Services\PurchaseClosing\PurchaseClosingService;
use App\Support\Financial\InstallmentSchedule;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class GeneratePurchaseClosingAccountPayableAction
{
    public static function make(): Action
    {
        return Action::make('generateAccountPayable')
            ->label('Gerar Conta a Pagar')
            ->icon(Heroicon::Banknotes)
            ->color('success')
            ->visible(fn (PurchaseClosing $record): bool => ! $record->account_payable_id
                && in_array($record->status, [Status::DRAFT, Status::REOPENED], true))
            ->modalWidth('lg')
            ->modalHeading('Gerar Conta a Pagar')
            ->schema(fn (PurchaseClosing $record): array => [
                Money::make('closing_net_amount')
                    ->label('Valor Líquido do Fechamento')
                    ->default((float) $record->net_amount)
                    ->disabled()
                    ->dehydrated(false),
                DatePicker::make('due_date')
                    ->label('Primeiro Vencimento')
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->default(now()->format('Y-m-d')),
                Select::make('payment_method')
                    ->label('Forma de Pagamento')
                    ->options(PaymentMethod::toSelectArray())
                    ->native(false)
                    ->searchable(),
                TextInput::make('installment_count')
                    ->label('Qtd. Parcelas')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(24)
                    ->default(1)
                    ->required()
                    ->live(),
                Select::make('installment_due_mode')
                    ->label('Prazo entre Parcelas')
                    ->options([
                        ...PaymentCondition::installmentIntervalOptions(),
                        InstallmentSchedule::FIXED_DAY_OF_MONTH => 'Dia fixo do mes',
                        InstallmentSchedule::CUSTOM_INTERVAL_DAYS => 'Intervalo personalizado',
                    ])
                    ->default(PaymentCondition::DAYS_30->value)
                    ->native(false)
                    ->live()
                    ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1),
                TextInput::make('installment_fixed_day')
                    ->label('Dia Fixo do Mês')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->required(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                        && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                    ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                        && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH),
                TextInput::make('installment_interval_days')
                    ->label('Intervalo em Dias')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(365)
                    ->required(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                        && $get('installment_due_mode') === InstallmentSchedule::CUSTOM_INTERVAL_DAYS)
                    ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                        && $get('installment_due_mode') === InstallmentSchedule::CUSTOM_INTERVAL_DAYS),
                Select::make('financial_category_id')
                    ->label('Categoria Financeira')
                    ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'payable'))
                    ->searchable()
                    ->preload()
                    ->native(false),
                TextInput::make('document_number')
                    ->label('Nº Documento')
                    ->maxLength(50)
                    ->placeholder($record->reference ?: 'Número/Referência do fechamento'),
                TextInput::make('description')
                    ->label('Descrição')
                    ->maxLength(255)
                    ->placeholder('Descrição da conta a pagar gerada'),
            ])
            ->action(function (PurchaseClosing $record, array $data): void {
                $service = app(PurchaseClosingService::class);
                $payable = $service->generateAccountPayable($record, $data, (int) Auth::id());

                if ($service->hasError() || $payable === null) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return;
                }

                notify::success('Conta a pagar gerada com sucesso.');

                redirect(AccountPayableResource::getUrl('edit', [
                    'record' => $payable,
                    'tenant' => Filament::getTenant(),
                ]));
            });
    }
}
