<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\EditAccountReceivable;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountReceivable;
use App\Models\CardPaymentProfile;
use App\Models\FinancialCategory;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Services\Financial\CardReceivableCalculatorService;
use App\Support\Financial\InstallmentSchedule;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Leandrocfe\FilamentPtbrFormFields\Money;

class AccountReceivableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 12,
            ])
            ->components([
                Section::make('Lancamento a Receber')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        SelectPartner::make('customer_id', 'all')
                            ->label('Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->required(),
                        Select::make('invoice_id')
                            ->label('Fatura')
                            ->columnSpan(['md' => 1])
                            ->relationship('invoice', 'invoice_number')
                            ->disabled()
                            ->visibleOn('edit'),
                        TextInput::make('installment_count')
                            ->label('Qtd. Parcelas')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->default(1)
                            ->required()
                            ->live()
                            ->visibleOn('create')
                            ->helperText('Se maior que 1, serão geradas parcelas automaticas a partir do primeiro vencimento.'),
                        Select::make('installment_due_mode')
                            ->label('Intervalo das Parcelas')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options([
                                ...PaymentCondition::installmentIntervalOptions(),
                                InstallmentSchedule::FIXED_DAY_OF_MONTH => 'Dia fixo do mes',
                                InstallmentSchedule::CUSTOM_INTERVAL_DAYS => 'Intervalo personalizado',
                            ])
                            ->default(PaymentCondition::DAYS_30->value)
                            ->native(false)
                            ->live()
                            ->visibleOn('create')
                            ->visible(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1)
                            ->helperText('Defina o intervalo entre vencimentos usando condições de prazo, dia fixo do mês ou intervalo personalizado.'),
                        TextInput::make('installment_fixed_day')
                            ->label('Dia Fixo do Mes')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                            ->visibleOn('create')
                            ->visible(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                            ->helperText('Usado da 2ª parcela em diante. Se o mês nã tiver esse dia, será utilizado o ultimo dia do mês.'),
                        TextInput::make('installment_interval_days')
                            ->label('Intervalo em Dias')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::CUSTOM_INTERVAL_DAYS)
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::CUSTOM_INTERVAL_DAYS)
                            ->helperText('Define o intervalo de dias entre uma parcela e outra.'),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::PENDING->value)
                            ->visibleOn('edit')
                            ->disabled(),
                    ]),
                Section::make('Valores e Datas')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('due_date')
                            ->label('Data de Vencimento')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->required()
                            ->displayFormat('d/m/Y'),
                        Money::make('due_amount')
                            ->label('Valor à Receber')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->required(),
                        DatePicker::make('paid_date')
                            ->label('Data de Recebimento')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->nullable()
                            ->visibleOn('edit')
                            ->disabled()
                            ->helperText('A baixa é controlada através das parcelas do contas à receber.'),
                        Money::make('paid_amount')
                            ->label('Valor Recebido')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->default(0)
                            ->visibleOn('edit')
                            ->disabled(),
                    ]),
                Section::make('Informações Adicionais')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        TextInput::make('document_number')
                            ->label('Nº Documento')
                            ->columnSpan(['md' => 2])
                            ->maxLength(50),
                        TextInput::make('description')
                            ->label('Descrição Base')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->maxLength(255)
                            ->helperText('Usada como sugestão para as parcelas quando nenhuma descrição individual for informada.'),
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->live(),
                        Select::make('card_payment_profile_id')
                            ->label('Perfil de Cartao')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => CardPaymentProfile::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visible(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                            ->required(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                            ->live(),
                        DatePicker::make('payment_date')
                            ->label('Data da Venda no Cartao')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->visible(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                            ->required(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                            ->live(),
                        Placeholder::make('card_fee_preview')
                            ->label('Taxa calculada')
                            ->content(fn (callable $get): string => static::buildCardFeePreview($get))
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visible(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value),
                        Placeholder::make('card_net_preview')
                            ->label('Liquido previsto')
                            ->content(fn (callable $get): string => static::buildCardNetPreview($get))
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visible(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value),
                        Placeholder::make('card_settlement_preview')
                            ->label('Previsao de recebimento')
                            ->content(fn (callable $get): string => static::buildCardSettlementPreview($get))
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->visible(fn (callable $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value),
                        Select::make('financial_category_id')
                            ->label('Categoria Financeira')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'receivable'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visibleOn('create')
                            ->helperText('A categoria será aplicada às parcelas geradas para esta conta.'),
                        Toggle::make('paid')
                            ->label('Recebido')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default(false)
                            ->visibleOn('edit')
                            ->disabled()
                            ->helperText('Controle automático por parcelas.'),
                    ]),
                Livewire::make(InstallmentsRelationManager::class, fn(AccountReceivable $record) => [
                    'ownerRecord' => $record,
                    'pageClass' => EditAccountReceivable::class,
                ])
                    ->key('installments-relation-manager')
                    ->columnSpanFull()
                    ->visibleOn([Operation::Edit]),
                Livewire::make(PaymentsRelationManager::class, fn(AccountReceivable $record) => [
                    'ownerRecord' => $record,
                    'pageClass' => EditAccountReceivable::class,
                ])
                    ->key('payments-relation-manager')
                    ->columnSpanFull()
                    ->visibleOn([Operation::Edit]),
                Hidden::make('company_id'),
            ]);
    }

    private static function buildCardFeePreview(callable $get): string
    {
        $preview = static::resolveCardCalculationPreview($get);

        if ($preview === null) {
            return '-';
        }

        return 'R$ ' . number_format((float) $preview->feeAmount, 2, ',', '.');
    }

    private static function buildCardNetPreview(callable $get): string
    {
        $preview = static::resolveCardCalculationPreview($get);

        if ($preview === null) {
            return '-';
        }

        return 'R$ ' . number_format((float) $preview->netAmount, 2, ',', '.');
    }

    private static function buildCardSettlementPreview(callable $get): string
    {
        $preview = static::resolveCardCalculationPreview($get);

        if ($preview === null) {
            return '-';
        }

        return $preview->expectedSettlementDate;
    }

    private static function resolveCardCalculationPreview(callable $get): ?\App\Domain\DTO\Financial\CardReceivableCalculationDTO
    {
        $profileId = (int) ($get('card_payment_profile_id') ?? 0);

        if ($profileId <= 0) {
            return null;
        }

        $profile = CardPaymentProfile::query()->find($profileId);

        if (! $profile) {
            return null;
        }

        $grossAmount = static::normalizeMoneyValue($get('due_amount') ?? $get('gross_amount'));

        if ($grossAmount <= 0) {
            return null;
        }

        $paymentDate = (string) ($get('payment_date') ?? $get('due_date') ?? '');

        if ($paymentDate === '') {
            return null;
        }

        return app(CardReceivableCalculatorService::class)->calculateFromProfile(
            $profile,
            $grossAmount,
            $paymentDate,
        );
    }

    private static function normalizeMoneyValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (! is_string($value) || $value === '') {
            return 0;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $value);

        return round((float) $normalized, 2);
    }
}
