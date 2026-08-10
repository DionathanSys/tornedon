<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Schemas;

use App\Enum\AccountPayable\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\ResultCenter;
use App\Support\Financial\InstallmentSchedule;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class AccountPayableForm
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
                Section::make('Lancamento a Pagar')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_manual_counterparty')
                            ->label('Parceiro Avulso?')
                            ->disabledOn('edit')
                            ->live()
                            ->inline(false)
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Toggle $component, ?bool $state, ?object $record): void {
                                if (! $record) {
                                    return;
                                }

                                $component->state($record->supplier_id === null && filled($record->manual_counterparty_name));
                            })
                            ->afterStateUpdated(function (bool $state, Set $set): void {
                                if ($state) {
                                    $set('supplier_id', null);

                                    return;
                                }

                                $set('manual_counterparty_name', null);
                            })
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        SelectPartner::make('supplier_id', 'all')
                            ->label('Fornecedor')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->required(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false))
                            ->hidden(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false)),
                        TextInput::make('manual_counterparty_name')
                            ->label('Nome da Contraparte')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false))
                            ->hidden(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false)),
                        Select::make('fiscal_document_id')
                            ->label('Documento Fiscal')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship('fiscalDocument', 'document_number')
                            ->searchable()
                            ->native(false)
                            ->disabled()
                            ->visibleOn('edit')
                            ->nullable(),
                        TextInput::make('installment_count')
                            ->label('Qtd. Parcelas')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->columnSpanFull(1)
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->default(1)
                            ->required()
                            ->live()
                            ->visibleOn('create')
                            ->helperText('Se maior que 1, serão geradas parcelas automáticas a partir do primeiro vencimento.'),
                        Select::make('installment_due_mode')
                            ->label('Prazo entre Parcelas')
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
                            ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1)
                            ->helperText('Defina o intervalo entre vencimentos usando as condições de prazo ou escolha um dia fixo do mês.'),
                        TextInput::make('installment_fixed_day')
                            ->label('Dia Fixo do Mês')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                            ->helperText('Usado da 2ª parcela em diante. Se o mês não tiver esse dia, será usado o último dia do mês.'),
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
                        Select::make('amount_input_mode')
                            ->label('Como Informar o Valor')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->options([
                                'total' => 'Valor total da conta',
                                'per_installment' => 'Valor de cada parcela',
                            ])
                            ->default('total')
                            ->native(false)
                            ->live()
                            ->visibleOn('create'),
                        Money::make('due_amount')
                            ->label(fn (callable $get): string => $get('amount_input_mode') === 'per_installment'
                                ? 'Valor de Cada Parcela'
                                : 'Valor Total da Conta')
                            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->required()
                            ->helperText(fn (callable $get): string => $get('amount_input_mode') === 'per_installment'
                                ? 'O total da conta será calculado pela quantidade de parcelas.'
                                : 'O valor total será distribuido entre as parcelas.'),
                        DatePicker::make('paid_date')
                            ->label('Data de Pagamento')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->nullable()
                            ->disabled()
                            ->helperText('A baixa é controlada nas parcelas da conta a pagar.'),
                        Money::make('paid_amount')
                            ->label('Valor Pago')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->default(0)
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
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->autocomplete(false)
                            ->maxLength(50),
                        TextInput::make('bank_slip_number')
                            ->label('Nº do Boleto')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->autocomplete(false)
                            ->maxLength(100),
                        TextInput::make('note_number')
                            ->label('Nº da Nota')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->autocomplete(false)
                            ->maxLength(100),
                        TextInput::make('description')
                            ->label('Descrição Base')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->autocomplete(false)
                            ->maxLength(255)
                            ->helperText('Usada como sugestão para as parcelas quando nenhuma descrição individual for informada.'),
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable(),
                        Select::make('financial_category_id')
                            ->label('Categoria Financeira')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'payable'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visibleOn('create')
                            ->helperText('A categoria será aplicada às parcelas geradas para esta conta.'),
                        Select::make('cost_center_id')
                            ->label('Centro de Custo')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => CostCenter::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visibleOn('create')
                            ->helperText('Aplicado às parcelas geradas para esta conta.'),
                        Select::make('result_center_id')
                            ->label('Centro de Resultado')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => ResultCenter::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visibleOn('create')
                            ->helperText('Aplicado às parcelas geradas para esta conta.'),
                        Toggle::make('is_effective')
                            ->label('Efetivada?')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(false)
                            ->live()
                            ->visibleOn('create')
                            ->helperText('Quando marcada, irá gerar a baixa das parcelas na data de vencimento.'),
                        Toggle::make('auto_register_payment_on_due_date')
                            ->label('Registrar automaticamente o pagamento na data de vencimento?')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->default(false)
                            ->live()
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (bool) ($get('is_effective') ?? true))
                            ->helperText('Um servico diario verifica as parcelas vencendo hoje e registra a baixa automaticamente.'),
                        Select::make('auto_payment_financial_account_id')
                            ->label('Conta Financeira do Pagamento Automatico')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(fn (callable $get): bool => (bool) ($get('auto_register_payment_on_due_date') ?? false))
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (bool) ($get('is_effective') ?? true)
                                && (bool) ($get('auto_register_payment_on_due_date') ?? false)),
                        Toggle::make('paid')
                            ->label('Pago')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default(false)
                            ->disabled()
                            ->visibleOn('edit')
                            ->helperText('Controle automático por parcelas.'),

                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
