<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Schemas;

use App\Enum\Tax\IssExigibility;
use App\Filament\Components\HelpPopover;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 8,
            ])
            ->components([
                Section::make('Informacoes do Servico')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome do Servico')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false),
                        Textarea::make('description')
                            ->label('Descricao')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(3)
                            ->maxLength(2000)
                            ->autocomplete(false),
                        TextInput::make('category')
                            ->label('Categoria')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->maxLength(255)
                            ->autocomplete(false),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->inline(false)
                            ->default(true),
                        Toggle::make('requires_approval')
                            ->label('Requer Aprovacao')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->inline(false)
                            ->default(false),
                        Toggle::make('accept_customer_discount')
                            ->label('Aceita Desconto do Cliente')
                            ->helperText('Permite aplicar automaticamente o desconto percentual do cadastro do cliente ao inserir este servico.')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->inline(false)
                            ->default(false),
                    ]),

                Section::make('Valores')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Money::make('price')
                            ->label('Preco')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->required()
                            ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                        Money::make('min_sale_price')
                            ->label('Preco Minimo')
                            ->helperText('O preco efetivo deste servico, apos desconto, nao pode ficar abaixo deste valor.')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                        Money::make('cost')
                            ->label('Custo')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                    ]),

                Section::make('Informacoes Fiscais')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('nbs_code')
                            ->label('Codigo NBS')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->helperText('Nomenclatura Brasileira de Servicos'),
                        TextInput::make('cnae_code')
                            ->label('Codigo CNAE')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->helperText('Classificacao Nacional de Atividades Economicas'),
                        TextInput::make('municipal_tax_code')
                            ->label('Codigo Tributacao Municipal')
                            ->belowContent(HelpPopover::make(
                                'Codigo de Tributacao Municipal',
                                'Informe o codigo do servico conforme tabela da prefeitura do municipio de incidencia do ISS. Este valor pode variar por cidade (ex.: 14.01, 7.02, 0101).'
                            ))
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false),
                        TextInput::make('tax_classification')
                            ->label('Classificacao Fiscal')
                            ->beforeContent(HelpPopover::make(
                                'Classificacao Fiscal do Servico',
                                'Informe o item/subitem da LC 116/2003 correspondente ao servico prestado. Essa classificacao define o enquadramento fiscal do servico.'
                            ))
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->visible(false)
                            ->helperText('Codigo do servico prestado Item da LC 116/2003'),
                        Money::make('tax_rate')
                            ->label('Aliquota Imposto (%)')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->suffix('%')
                            ->prefix(null)
                            ->default(0)
                            ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->maxValue(100),
                        Select::make('iss_exigibility')
                            ->label('Exigibilidade do ISS')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(IssExigibility::toSelectArray())
                            ->native(false)
                            ->searchable(),
                    ]),

                Section::make('Informacoes Adicionais')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->persistCollapsed()
                    ->schema([
                        KeyValue::make('additional_info')
                            ->label('Informacoes Adicionais')
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->addActionLabel('Adicionar informacao'),
                    ]),
            ]);
    }
}
