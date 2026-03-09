<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Partner;
use App\Enum\Product\Origin;
use App\Enum\Product\Unit;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identificação')
                    ->schema([
                        Forms\Components\Select::make('company_id')
                            ->label('Empresa Emitente')
                            ->options(Company::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('customer_id')
                            ->label('Cliente / Destinatário')
                            ->options(Partner::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('invoice_id')
                            ->label('Fatura Vinculada')
                            ->options(Invoice::pluck('invoice_number', 'id'))
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->helperText('Opcional — associa a NF-e a uma fatura existente.')
                            ->columnSpan(['md' => 2]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                Section::make('Dados da NF-e')
                    ->schema([
                        Forms\Components\TextInput::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->required()
                            ->maxLength(100)
                            ->default('VENDA DENTRO DO ESTADO')
                            ->columnSpan(['md' => 2]),

                        Forms\Components\DatePicker::make('issued_at')
                            ->label('Data de Emissão')
                            ->required()
                            ->native(false)
                            ->default(now())
                            ->columnSpan(['md' => 1]),

                        Forms\Components\DatePicker::make('movement_at')
                            ->label('Data Entrada/Saída')
                            ->required()
                            ->native(false)
                            ->default(now())
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('operation_type')
                            ->label('Tipo de Operação')
                            ->options([
                                1 => 'Saída (Venda)',
                                0 => 'Entrada (Compra)',
                            ])
                            ->default(1)
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('issue_purpose')
                            ->label('Finalidade de Emissão')
                            ->options([
                                '1' => '1 – NF-e Normal',
                                '2' => '2 – NF-e Complementar',
                                '3' => '3 – NF-e de Ajuste',
                                '4' => '4 – Devolução de Mercadoria',
                            ])
                            ->default('1')
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Toggle::make('is_final_consumer')
                            ->label('Consumidor Final')
                            ->default(true)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('buyer_presence_indicator')
                            ->label('Indicador de Presença')
                            ->options([
                                '0' => '0 – Não se aplica',
                                '1' => '1 – Operação presencial',
                                '2' => '2 – Operação não presencial (internet)',
                                '3' => '3 – Operação não presencial (teleatendimento)',
                                '4' => '4 – NFC-e entrega domiciliar',
                                '9' => '9 – Operação não presencial (outros)',
                            ])
                            ->default('9')
                            ->native(false)
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                Section::make('Itens da Nota Fiscal')
                    ->description('Produtos / peças que compõem a NF-e.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Produto')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->required()
                                    ->native(false)
                                    ->columnSpan(['md' => 2]),

                                Forms\Components\TextInput::make('description')
                                    ->label('Descrição')
                                    ->maxLength(255)
                                    ->columnSpan(['md' => 2]),

                                Forms\Components\TextInput::make('ncm_code')
                                    ->label('NCM')
                                    ->required()
                                    ->maxLength(8)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('cest_code')
                                    ->label('CEST')
                                    ->maxLength(9)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('cfop_code')
                                    ->label('CFOP')
                                    ->required()
                                    ->maxLength(4)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('barcode')
                                    ->label('Código de Barras')
                                    ->maxLength(60)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\Select::make('product_origin')
                                    ->label('Origem')
                                    ->options(Origin::toSelectArray())
                                    ->default('0')
                                    ->native(false)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\Select::make('unit_of_measure')
                                    ->label('Unidade Comercial')
                                    ->options(Unit::toSelectArray())
                                    ->default('UN')
                                    ->native(false)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Valor Unitário')
                                    ->numeric()
                                    ->required()
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('total_price')
                                    ->label('Valor Total')
                                    ->numeric()
                                    ->required()
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\Select::make('taxable_unit')
                                    ->label('Unidade Tributável')
                                    ->options(Unit::toSelectArray())
                                    ->native(false)
                                    ->helperText('Se diferente da unidade comercial')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('taxable_quantity')
                                    ->label('Qtd. Tributável')
                                    ->numeric()
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('taxable_unit_price')
                                    ->label('Valor Unit. Tributável')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('discount_amount')
                                    ->label('Desconto')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('freight_amount')
                                    ->label('Frete')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('insurance_amount')
                                    ->label('Seguro')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('other_expenses_amount')
                                    ->label('Outras Despesas')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix('R$')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\Toggle::make('included_in_total')
                                    ->label('Inclui no Total')
                                    ->default(true)
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\Textarea::make('additional_information')
                                    ->label('Informações Adicionais do Item')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->columnSpan(['md' => 2]),

                                Forms\Components\KeyValue::make('tax_data')
                                    ->label('Dados Tributários (JSON)')
                                    ->helperText('Campo avançado — structure: {imposto: {icms,pis,cofins}}')
                                    ->columnSpan(['md' => 2]),
                            ])
                            ->columns(['md' => 2])
                            ->reorderable()
                            ->addActionLabel('Adicionar Item'),
                    ])
                    ->collapsible(),

                Section::make('Frete')
                    ->schema([
                        Forms\Components\Select::make('freight_data.modalidade_frete')
                            ->label('Modalidade do Frete')
                            ->options([
                                '0' => '0 Por conta do emitente (CIF)',
                                '1' => '1 Por conta do destinatário (FOB)',
                                '2' => '2 Por conta de terceiros',
                                '9' => '9 Sem frete',
                            ])
                            ->default('9')
                            ->native(false)
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                Section::make('Informações Adicionais')
                    ->schema([
                        Forms\Components\Textarea::make('additional_taxpayer_information')
                            ->label('Informações ao Contribuinte')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpan(['md' => 2]),

                        Forms\Components\Textarea::make('additional_tax_information')
                            ->label('Informações ao Fisco')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpan(['md' => 2]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),
            ]);
    }
}
