<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Schemas;

use App\Enum\Tax\IssExigibility;
use App\Filament\Components\HelpPopover;
use App\Models\CompanyPreference;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
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
                Section::make('Informações do Serviço')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Group::make()
                            ->columns(['sm' => 1, 'md' => 4, 'lg' => 8])
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('code')
                                    ->label('Código')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->readOnly()
                                    ->saved(false)
                                    ->autocomplete(false),
                                TextInput::make('name')
                                    ->label('Nome do Serviço')
                                    ->columnSpan(['md' => 3, 'lg' => 6])
                                    ->required()
                                    ->maxLength(255)
                                    ->autocomplete(false),
                            ]),
                        Textarea::make('description')
                            ->label('Descrição')
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
                            ->label('Requer Aprovação')
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
                            ->label('Preço')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->required()
                            ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                        Money::make('min_sale_price')
                            ->label('Preço Mínimo')
                            ->helperText('O preço efetivo deste serviço, após desconto, não pode ficar abaixo deste valor.')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                        Money::make('cost')
                            ->label('Custo')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->default(0),
                    ]),

                Section::make('Informações Fiscais')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('nbs_code')
                            ->label('Código NBS')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->default(fn (\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultNbsCode())
                            ->helperText('Nomenclatura Brasileira de Serviços'),
                        TextInput::make('cnae_code')
                            ->label('Código CNAE')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->helperText('Classificação Nacional de Atividades Econômicas'),
                        TextInput::make('municipal_tax_code')
                            ->label('Código Tributação Municipal')
                            ->belowContent(HelpPopover::make(
                                'Código de Tributação Municipal',
                                'Informe o código do serviço conforme tabela da prefeitura do município de incidência do ISS. Este valor pode variar por cidade (ex.: 14.01, 7.02, 0101).'
                            ))
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->default(fn (\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultMunicipalTaxCode()),
                        TextInput::make('tax_classification')
                            ->label('Classificação Fiscal')
                            ->beforeContent(HelpPopover::make(
                                'Classificação Fiscal do Serviço',
                                'Informe o item/subitem da LC 116/2003 correspondente ao serviço prestado. Essa classificação define o enquadramento fiscal do serviço.'
                            ))
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->visible(false)
                            ->helperText('Código do serviço prestado Item da LC 116/2003'),
                        Money::make('tax_rate')
                            ->label('Alíquota Imposto (%)')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->suffix('%')
                            ->prefix(null)
                            ->default(fn (\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultIssRate() ?? null)
                            ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->maxValue(100),
                        Select::make('iss_exigibility')
                            ->label('Exigibilidade do ISS')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(IssExigibility::toSelectArray())
                            ->native(false)
                            ->searchable(),
                    ]),

                Section::make('Informacões Adicionais')
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
                            ->label('Informacões Adicionais')
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->addActionLabel('Adicionar Informação'),
                    ]),
            ]);
    }
}
