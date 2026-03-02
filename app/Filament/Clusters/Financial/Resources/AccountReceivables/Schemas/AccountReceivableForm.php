<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
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
                        Select::make('customer_id')
                            ->label('Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('invoice_id')
                            ->label('Fatura')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship('invoice', 'invoice_number')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        TextInput::make('sequence_number')
                            ->label('Parcela')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->required()
                            ->maxLength(2)
                            ->default('01'),
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
                            ->nullable(),
                        Money::make('paid_amount')
                            ->label('Valor Recebido')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->default(0)
                            ->prefix('R$'),
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
                            ->maxLength(50),
                        TextInput::make('description')
                            ->label('Descrição')
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
                            ->default(false),
                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
