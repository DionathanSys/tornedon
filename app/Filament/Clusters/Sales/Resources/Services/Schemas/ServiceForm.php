<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Schemas;

use App\Enum\Product\Unit;
use App\Enum\Tax\IssExigibility;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
                Tabs::make('ServiceTabs')
                    ->columnSpanFull()
                    ->vertical()
                    ->activeTab(1)
                    ->tabs([
                        Tab::make('Geral')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Section::make('Informações do Serviço')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Nome do Serviço')
                                            ->columnSpan(['md' => 4, 'lg' => 8])
                                            ->required()
                                            ->maxLength(255)
                                            ->autocomplete(false),
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
                                    ]),
                            ]),
                        Tab::make('Preços')
                            ->icon(Heroicon::CurrencyDollar)
                            ->schema([
                                Section::make('Valores')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Money::make('price')
                                            ->label('Preço')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->required()
                                            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                        Money::make('cost')
                                            ->label('Custo')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                    ]),
                            ]),
                        Tab::make('Tributação')
                            ->icon(Heroicon::DocumentText)
                            ->schema([
                                Section::make('Informações Fiscais')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        TextInput::make('nbs_code')
                                            ->label('Código NBS')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(50)
                                            ->autocomplete(false)
                                            ->helperText('Nomenclatura Brasileira de Serviços'),
                                        TextInput::make('cnae_code')
                                            ->label('Código CNAE')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(50)
                                            ->autocomplete(false)
                                            ->helperText('Classificação Nacional de Atividades Econômicas'),
                                        TextInput::make('municipal_tax_code')
                                            ->label('Código Tributação Municipal')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(50)
                                            ->autocomplete(false)
                                            ->helperText('Código do serviço do município'),
                                        TextInput::make('tax_classification')
                                            ->label('Classificação Fiscal')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->helperText('Código do serviço prestado Item da LC 116/2003'),
                                        Money::make('tax_rate')
                                            ->label('Alíquota Imposto (%)')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->suffix('%')
                                            ->prefix(null)
                                            ->default(0)
                                            ->maxValue(100),
                                        Select::make('iss_exigibility')
                                            ->label('Exigibilidade do ISS')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->options(IssExigibility::toSelectArray())
                                            ->native(false)
                                            ->searchable(),
                                    ]),
                            ]),
                        Tab::make('Outros')
                            ->icon(Heroicon::EllipsisHorizontal)
                            ->schema([
                                Section::make('Informações Adicionais')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        KeyValue::make('additional_info')
                                            ->label('Informações Adicionais')
                                            ->keyLabel('Chave')
                                            ->valueLabel('Valor')
                                            ->columnSpan(['md' => 4, 'lg' => 8])
                                            ->addActionLabel('Adicionar informação'),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
