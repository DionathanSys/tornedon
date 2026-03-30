<?php

namespace App\Filament\Mobile\Resources\Services\Schemas;

use App\Enum\Tax\IssExigibility;
use App\Filament\Components\HelpPopover;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
            ->components([
                TextInput::make('service_code')
                    ->label('Código')
                    ->readOnly()
                    ->saved(false)
                    ->autocomplete(false),
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255)
                    ->autocomplete(false),
                Textarea::make('description')
                    ->label('Descrição')
                    ->columnSpanFull()
                    ->rows(3)
                    ->maxLength(2000)
                    ->autocomplete(false),
                Money::make('price')
                    ->label('Preço')
                    ->required()
                    ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->default(0),
                Money::make('min_sale_price')
                    ->label('Preço Mínimo')
                    ->helperText('O preço efetivo após desconto não pode ficar abaixo deste valor.')
                    ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->default(0),
                Money::make('cost')
                    ->label('Custo')
                    ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                    ->default(0),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->required(),
                        Toggle::make('requires_approval')
                            ->label('Requer Aprovação')
                            ->required(),
                        Toggle::make('accept_customer_discount')
                            ->label('Aceita desconto do cliente')
                            ->helperText('Aplica automaticamente o desconto do cadastro do cliente quando o serviço for inserido em OS ou Orçamento.')
                            ->default(false),
                    ]),
                Section::make('Informações Fiscais')
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(false)
                    ->schema([
                        TextInput::make('nbs_code')
                            ->label('Código NBS')
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->default(fn(\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultNbsCode())
                            ->helperText('Nomenclatura Brasileira de Serviços'),
                        TextInput::make('cnae_code')
                            ->label('Código CNAE')
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->helperText('Classificação Nacional de Atividades Econômicas'),
                        TextInput::make('municipal_tax_code')
                            ->label('Código Tributação Municipal')
                            ->belowContent(HelpPopover::make(
                                'Código de Tributação Municipal',
                                'Informe o código do serviço conforme tabela da prefeitura do município de incidência do ISS. Este valor pode variar por cidade (ex.: 14.01, 7.02, 0101).'
                            ))
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->default(fn(\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultMunicipalTaxCode()),
                        TextInput::make('tax_classification')
                            ->label('Classificação Fiscal')
                            ->beforeContent(HelpPopover::make(
                                'Classificação Fiscal do Serviço',
                                'Informe o item/subitem da LC 116/2003 correspondente ao serviço prestado. Essa classificação define o enquadramento fiscal do serviço.'
                            ))
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->visible(false)
                            ->helperText('Código do serviço prestado Item da LC 116/2003'),
                        Money::make('tax_rate')
                            ->label('Alíquota Imposto (%)')
                            ->suffix('%')
                            ->prefix(null)
                            ->default(fn(\App\Services\Fiscal\FiscalProfileService $service) => $service->getDefaultIssRate() ?? null)
                            ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                            ->maxValue(100),
                        Select::make('iss_exigibility')
                            ->label('Exigibilidade do ISS')
                            ->options(IssExigibility::toSelectArray())
                            ->native(false)
                            ->searchable(),
                    ]),
            ]);
    }
}
