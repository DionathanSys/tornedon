<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Schemas;

use App\Enum\AccountPayable\Status;
use App\Enum\Partner\Type;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\EditAccountPayable;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountPayable;
use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
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
                Section::make('Dados da Conta a Pagar')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        SelectPartner::make('supplier_id', 'all')
                            ->label('Fornecedor')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->required(),
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
                                'fixed_day_of_month' => 'Dia fixo do mês',
                            ])
                            ->default(PaymentCondition::DAYS_30->value)
                            ->native(false)
                            ->live()
                            ->visibleOn('create')
                            ->visible(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1)
                            ->helperText('Defina o intervalo entre vencimentos usando as condições de prazo ou escolha um dia fixo do mês.'),
                        TextInput::make('installment_fixed_day')
                            ->label('Dia Fixo do Mês')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === 'fixed_day_of_month')
                            ->visibleOn('create')
                            ->visible(fn(callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === 'fixed_day_of_month')
                            ->helperText('Usado da 2ª parcela em diante. Se o mês não tiver esse dia, será usado o último dia do mês.'),
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
                            ->label('Valor a Pagar')
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->required(),
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
                            ->label('Descrição')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->autocomplete(false)
                            ->maxLength(255),
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
                            ->helperText('A categoria sera aplicada as parcelas geradas para esta conta.'),
                        Toggle::make('paid')
                            ->label('Pago')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default(false)
                            ->disabled()
                            ->helperText('Controle automático por parcelas.'),

                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
