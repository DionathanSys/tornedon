<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Schemas;

use App\Enum\Product\ItemType;
use App\Enum\Product\Origin;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Filament\Components\NcmCodeInput;
use App\Models\Category;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ProductForm
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
                Tabs::make('ProductTabs')
                    ->columnSpanFull()
                    ->vertical()
                    ->activeTab(1)
                    ->tabs([
                        Tab::make('Geral')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Section::make('Informações do Produto')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        TextInput::make('product_code')
                                            ->label('Código do Produto')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(60)
                                            ->disabled(),
                                        TextInput::make('name')
                                            ->label('Nome')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->required()
                                            ->maxLength(255)
                                            ->autocomplete(false),
                                        Textarea::make('description')
                                            ->label('Descrição')
                                            ->columnSpan(['md' => 4, 'lg' => 8])
                                            ->rows(3)
                                            ->maxLength(500)
                                            ->autocomplete(false),
                                        Select::make('category_id')
                                            ->label('Categoria')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->relationship(
                                                name: 'category',
                                                titleAttribute: 'name',
                                                modifyQueryUsing: fn($query) => $query
                                                    ->where('company_id', Filament::getTenant()->id)
                                                    ->where('is_active', true)
                                                    ->orderBy('name')
                                            )
                                            ->searchable()
                                            ->preload()
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->label('Nome')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                                                        $tenant = Filament::getTenant();
                                                        return $rule->where('company_id', $tenant->id);
                                                    })
                                                    ->autocomplete(false),
                                                Textarea::make('description')
                                                    ->label('Descrição')
                                                    ->rows(2)
                                                    ->maxLength(500)
                                                    ->autocomplete(false),
                                            ])
                                            ->createOptionUsing(function (array $data): int {
                                                $tenant = Filament::getTenant();
                                                $data['company_id'] = $tenant->id;
                                                $data['created_by'] = Auth::id();
                                                $data['updated_by'] = Auth::id();
                                                $data['is_active'] = true;

                                                return Category::create($data)->getKey();
                                            }),
                                        Select::make('unit')
                                            ->label('Unidade')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->options(Unit::toSelectArray())
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set): void {
                                                if (! $state) {
                                                    return;
                                                }

                                                $saleUnit = $get('sale_unit');
                                                $availableUnits = array_keys(self::saleUnitOptions($get));

                                                if ($saleUnit === null || $saleUnit === $old || ! in_array($saleUnit, $availableUnits, true)) {
                                                    $set('sale_unit', $state);
                                                }
                                            })
                                            ->disabledOn('edit')
                                            ->native(false)
                                            ->default('UN'),
                                        Select::make('sale_unit')
                                            ->label('Unid. Padrão Venda')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->options(fn (Get $get): array => self::saleUnitOptions($get))
                                            ->required()
                                            ->native(false)
                                            ->default('UN')
                                            ->helperText('Usada como unidade inicial na requisição. Por padrão, segue a unidade base do produto.'),
                                        Select::make('item_type')
                                            ->label('Tipo de Item')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->options(ItemType::toSelectArray())
                                            ->native(false),
                                        TextInput::make('manufacturer_code')
                                            ->label('Código Fábrica')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(100)
                                            ->autocomplete(false),
                                        TextInput::make('gross_weight')
                                            ->label('Peso Bruto')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->numeric()
                                            ->step('0.001')
                                            ->suffix('kg'),
                                        TextInput::make('net_weight')
                                            ->label('Peso Líquido')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->numeric()
                                            ->step('0.001')
                                            ->suffix('kg'),
                                        TextInput::make('barcode')
                                            ->label('Código de Barras')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->maxLength(60)
                                            ->autocomplete(false),
                                        Toggle::make('is_custom_manufacturing')
                                            ->label('Fabricação Própria')
                                            ->columnStart(1)
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->inline(false)
                                            ->default(false),
                                        Toggle::make('has_stock_control')
                                            ->label('Controla Estoque?')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->inline(false)
                                            ->default(false),
                                        Toggle::make('is_active')
                                            ->label('Ativo')
                                            ->columnSpan(['md' => 1, 'lg' => 1])
                                            ->inline(false)
                                            ->default(true),
                                        //TODO: A ideia seria de ser um campo automatico para controlar se o produto pode ser vendido
                                        Toggle::make('is_invoiceable')
                                            ->label('Permite Venda')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 1])
                                            ->inline(false)
                                            ->default(true),
                                        KeyValue::make('external_reference_codes')
                                            ->label('Outros Códigos (Ref. / Cód.)')
                                            ->keyLabel('Ref.')
                                            ->valueLabel('Cód.')
                                            ->columnSpan(['md' => 4, 'lg' => 8])
                                            ->addActionLabel('Adicionar referência'),
                                    ]),

                            ]),
                        Tab::make('Preços')
                            ->icon(Heroicon::CurrencyDollar)
                            ->schema([
                                Section::make('Precificação')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->contained(false)
                                    ->schema([
                                        Money::make('profit_margin')
                                            ->label('Margem de Lucro (%)')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->default(0)
                                            ->suffix('%')
                                            ->prefix(null),
                                        Money::make('min_sale_price')
                                            ->label('Preço Mínimo de Venda')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                        Select::make('origin_sale_price')
                                            ->label('Origem do Preço de Venda')
                                            ->native(false)
                                            ->options(OriginSalePrice::toSelectArray())
                                            ->default(OriginSalePrice::CALCULATED_II->value)
                                            ->columnSpan(['md' => 1, 'lg' => 2]),
                                        Money::make('sale_price_value')
                                            ->label('Valor de Venda Fixo')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                            ->hiddenJs(<<<'JS'
                                                $get('origin_sale_price') !== 'fixed'
                                            JS)
                                            ->requiredIf('origin_sale_price', 'fixed'),
                                    ]),
                            ]),
                        Tab::make('Conversões')
                            ->icon(Heroicon::AdjustmentsHorizontal)
                            ->columnSpanFull()
                            ->schema([
                                Repeater::make('alternative_unit_conversions')
                                    ->label('Unidades alternativas')
                                    ->grid(2)
                                    ->columnSpanFull()
                                    ->helperText('Cadastre como a unidade alternativa se converte para a unidade padrão. Ex.: 1 CX = 2 JG.')
                                    ->default([])
                                    ->reorderable(false)
                                    ->collapsed()
                                    ->addActionLabel('Adicionar unidade alternativa')
                                    ->live()
                                    ->schema([
                                        Select::make('unit')
                                            ->label('Unidade alternativa')
                                            ->options(Unit::toSelectArray())
                                            ->required()
                                            ->disableOptionWhen(fn(Get $get, string $value): bool => $value === $get('../../unit'))
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->native(false),
                                        TextInput::make('conversion_factor')
                                            ->label('Fator para unidade padrão')
                                            ->helperText('Informe quantas unidades padrão existem em 1 unidade alternativa.')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0.00000001)
                                            ->step('0.00000001'),
                                    ])
                                    ->columns(2)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        $saleUnit = (string) ($get('sale_unit') ?? '');
                                        $availableUnits = array_keys(self::saleUnitOptions($get));

                                        if ($saleUnit !== '' && ! in_array($saleUnit, $availableUnits, true)) {
                                            $set('sale_unit', $get('unit') ?: Unit::UN->value);
                                        }
                                    }),
                            ]),
                        Tab::make('Impostos')
                            ->icon(Heroicon::CurrencyDollar)
                            ->visibleOn('edit')
                            ->schema([
                                Section::make('Tributação')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->schema([
                                        Select::make('tax.product_origin')
                                            ->label('Origem do Produto')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->options(Origin::toSelectArray())
                                            ->native(false),
                                        NcmCodeInput::make('tax.ncm_code'),
                                        TextInput::make('tax.cest_code')
                                            ->label('Código CEST')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->mask('99.999.99')
                                            ->placeholder('00.000.00')
                                            ->maxLength(9),

                                    ]),
                                Section::make()
                                    ->label('Regras ICMS')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 2,
                                    ])
                                    ->collapsible()
                                    ->persistCollapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Group::make()
                                            ->columns(2)
                                            ->schema([
                                                Select::make('tax.icms.tax_situation')
                                                    ->label('Situação Tributária')
                                                    ->native(false)
                                                    ->options([
                                                        '00' => '00 - Tributada integralmente',
                                                        '10' => '10 - Tributada e com cobrança do ICMS por substituição tributária',
                                                        '20' => '20 - Com redução de base de cálculo',
                                                        '30' => '30 - Isenta ou não tributada e com cobrança do ICMS por substituição tributária',
                                                        '40' => '40 - Isenta',
                                                        '41' => '41 - Não tributada',
                                                        '50' => '50 - Suspensão',
                                                        '51' => '51 - Diferimento',
                                                        '60' => '60 - ICMS cobrado anteriormente por substituição tributária',
                                                        '70' => '70 - Com redução de base de cálculo e cobrança do ICMS por substituição tributária',
                                                        '90' => '90 - Outras',
                                                    ]), //TODO falta mais opções
                                                Select::make('tax.icms.base_calculation_method')
                                                    ->label('Método de Cálculo da Base')
                                                    ->native(false)
                                                    ->options([
                                                        0 => '0 - margem de valor agregado (%)',
                                                        1 => '1 - pauta (valor)',
                                                        2 => '2 - preço tabelado máximo (valor)',
                                                        3 => '3 - valor da operação'
                                                    ]),
                                                Money::make('tax.icms.aliquot')
                                                    ->label('Alíquota (%)')
                                                    ->suffix('%'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                                Section::make()
                                    ->label('Regras IPI')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 2,
                                    ])
                                    ->collapsible()
                                    ->persistCollapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Group::make()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('tax.ipi.framing_code')
                                                    ->label('Cód. Enquadramento'),
                                                Select::make('tax.ipi.tax_situation')
                                                    ->label('Situação Tributária')
                                                    ->native(false)
                                                    ->options([
                                                        '00' => '00 - Entrada com recuperação de crédito',
                                                        '01' => '01 - Entrada tributada com alíquota zero',
                                                        '02' => '02 - Entrada isenta',
                                                        '03' => '03 - Entrada não tributada',
                                                        '04' => '04 - Entrada imune',
                                                        '05' => '05 - Entrada com suspensão',
                                                        '49' => '49 - Outras entradas',
                                                        '50' => '50 - Saída tributada com alíquota zero',
                                                        '51' => '51 - Saída isenta',
                                                        '52' => '52 - Saída não tributada',
                                                        '53' => '53 - Saída imune',
                                                        '54' => '54 - Saída com suspensão',
                                                        '55' => '55 - Saída que não se enquadra nas anteriores',
                                                        '99' => '99 - Outras saídas',
                                                    ]),
                                                Money::make('tax.ipi.aliquot')
                                                    ->label('Alíquota (%)')
                                                    ->suffix('%'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                                Section::make()
                                    ->label('Regras PIS')
                                    ->hidden()
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 2,
                                    ])
                                    ->collapsible()
                                    ->persistCollapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Group::make()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('tax.pis.key')
                                                    ->label('Chave'),
                                                TextInput::make('tax.pis.value')
                                                    ->label('Valor'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                                Section::make()
                                    ->label('Regras COFINS')
                                    ->hidden()
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 2,
                                        'lg' => 2,
                                    ])
                                    ->collapsible()
                                    ->persistCollapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Group::make()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('tax.cofins.key')
                                                    ->label('Chave'),
                                                TextInput::make('tax.cofins.value')
                                                    ->label('Valor'),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),

            ]);
    }

    private static function saleUnitOptions(Get $get): array
    {
        $labels = Unit::toSelectArray();
        $units = [];
        $baseUnit = $get('unit');

        if (is_string($baseUnit) && trim($baseUnit) !== '') {
            $units[] = $baseUnit;
        }

        foreach (($get('alternative_unit_conversions') ?? []) as $conversion) {
            $unit = $conversion['unit'] ?? null;

            if (is_string($unit) && trim($unit) !== '' && ! in_array($unit, $units, true)) {
                $units[] = $unit;
            }
        }

        $options = [];

        foreach ($units as $unit) {
            $options[$unit] = $labels[$unit] ?? $unit;
        }

        return $options;
    }
}
