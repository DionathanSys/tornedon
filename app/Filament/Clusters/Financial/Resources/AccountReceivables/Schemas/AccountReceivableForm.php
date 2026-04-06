<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                Section::make('Dados da Conta a Receber')
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
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship('invoice', 'invoice_number')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        TextInput::make('installment_count')
                            ->label('Qtd. Parcelas')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->default(1)
                            ->required()
                            ->live()
                            ->visibleOn('create')
                            ->helperText('Se maior que 1, serao geradas parcelas automaticas a partir do primeiro vencimento.'),
                        Select::make('installment_due_mode')
                            ->label('Intervalo das Parcelas')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options([
                                'interval_30_days' => 'A cada 30 dias',
                                'fixed_day_of_month' => 'Dia fixo do mes',
                            ])
                            ->default('interval_30_days')
                            ->native(false)
                            ->live()
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1)
                            ->helperText('Escolha se as proximas parcelas avancam em 30 dias ou em um dia fixo do mes.'),
                        TextInput::make('installment_fixed_day')
                            ->label('Dia Fixo do Mes')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === 'fixed_day_of_month')
                            ->visibleOn('create')
                            ->visible(fn (callable $get): bool => (int) ($get('installment_count') ?? 1) > 1
                                && $get('installment_due_mode') === 'fixed_day_of_month')
                            ->helperText('Usado da 2a parcela em diante. Se o mes nao tiver esse dia, sera usado o ultimo dia do mes.'),
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
                            ->label('Valor a Receber')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->required()
                            ->prefix('R$'),
                        DatePicker::make('paid_date')
                            ->label('Data de Recebimento')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->nullable()
                            ->disabled()
                            ->helperText('A baixa e controlada nas parcelas da conta a receber.'),
                        Money::make('paid_amount')
                            ->label('Valor Recebido')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->default(0)
                            ->prefix('R$')
                            ->disabled(),
                    ]),
                Section::make('Informacoes Adicionais')
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
                            ->label('N Documento')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->maxLength(50),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->maxLength(255),
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable(),
                        Toggle::make('paid')
                            ->label('Recebido')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default(false)
                            ->disabled()
                            ->helperText('Controle automatico por parcelas.'),
                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
