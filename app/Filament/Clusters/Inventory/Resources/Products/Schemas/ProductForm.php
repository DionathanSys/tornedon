<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Schemas;

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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                                            ->native(false)
                                            ->default('UN'),
                                        KeyValue::make('external_reference_codes')
                                            ->label('Outros Códigos (Ref. / Cód.)')
                                            ->keyLabel('Ref.')
                                            ->valueLabel('Cód.')
                                            ->columnSpan(['md' => 4, 'lg' => 8])
                                            ->addActionLabel('Adicionar referência'),
                                        TextInput::make('item_type')
                                            ->label('Tipo de Item')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->maxLength(60)
                                            ->autocomplete(false),
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
                                            ->label('Controla de Estoque?')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->inline(false)
                                            ->default(false),
                                        Toggle::make('is_active')
                                            ->label('Ativo')
                                            ->columnSpan(['md' => 1, 'lg' => 1])
                                            ->inline(false)
                                            ->default(true),
                                    ]),

                                Hidden::make('company_id'),
                                Hidden::make('created_by'),
                                Hidden::make('updated_by'),
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
                                    ->visibleOn('edit')
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
                                            ->default(0),
                                        Select::make('origin_sale_price')
                                            ->label('Origem do Preço de Venda')
                                            ->live(onBlur: true)
                                            ->options(OriginSalePrice::toSelectArray())
                                            ->default(OriginSalePrice::CALCULATED->value)
                                            ->columnSpan(['md' => 1, 'lg' => 2]),
                                        Money::make('sale_price_value')
                                            ->label('Valor de Venda Fixo')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->default(0)
                                            ->visibleJs(<<<'JS'
                                                $get('origin_sale_price') === 'fixed';
                                            JS)
                                            ->requiredIf('origin_sale_price', 'fixed'),
                                    ]),
                            ]),
                        Tab::make('Impostos')
                            ->icon(Heroicon::CurrencyDollar)
                            ->schema([
                                Section::make('Tributação')
                                    ->relationship('tax')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 8,
                                    ])
                                    ->columnSpanFull()
                                    ->visibleOn('edit')
                                    ->collapsible()
                                    ->contained(false)
                                    ->schema([
                                        Select::make('product_origin')
                                            ->label('Origem do Produto')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->options(Origin::toSelectArray())
                                            ->native(false),
                                        NcmCodeInput::make(),
                                        TextInput::make('cest_code')
                                            ->label('Código CEST')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->mask('99.999.99')
                                            ->placeholder('00.000.00')
                                            ->maxLength(9),
                                        Section::make('icms_group')
                                            ->label('ICMS')
                                            ->columns([
                                                'sm' => 1,
                                                'md' => 2,
                                                'lg' => 2,
                                            ])
                                            ->collapsible()
                                            ->persistCollapsed()
                                            ->columnSpanFull()
                                            ->schema([
                                                Repeater::make('icms')
                                                    ->hideLabel()
                                                    ->schema([
                                                        TextInput::make('key')
                                                            ->label('Chave')
                                                            ->required(),
                                                        TextInput::make('value')
                                                            ->label('Valor')
                                                            ->required(),
                                                    ])
                                                    ->columnSpanFull()
                                                    ->addActionLabel('Adicionar campo')
                                                    ->deletable(fn() => Auth::user()->is_admin)
                                                    ->reorderable(),
                                            ]),
                                        Section::make('ipi_group')
                                            ->label('IPI')
                                            ->columns([
                                                'sm' => 1,
                                                'md' => 2,
                                                'lg' => 2,
                                            ])
                                            ->collapsible()
                                            ->persistCollapsed()
                                            ->columnSpanFull()
                                            ->schema([
                                                Repeater::make('ipi')
                                                    ->hideLabel()
                                                    ->schema([
                                                        TextInput::make('key')
                                                            ->label('Chave')
                                                            ->required(),
                                                        TextInput::make('value')
                                                            ->label('Valor')
                                                            ->required(),
                                                    ])
                                                    ->columnSpanFull()
                                                    ->addActionLabel('Adicionar campo')
                                                    ->deletable(fn() => Auth::user()->is_admin)
                                                    ->reorderable(),
                                            ]),
                                        Section::make('pis_group')
                                            ->label('PIS')
                                            ->columns([
                                                'sm' => 1,
                                                'md' => 2,
                                                'lg' => 2,
                                            ])
                                            ->collapsible()
                                            ->persistCollapsed()
                                            ->columnSpanFull()
                                            ->schema([
                                                Repeater::make('pis')
                                                    ->hideLabel()
                                                    ->schema([
                                                        TextInput::make('key')
                                                            ->label('Chave')
                                                            ->required(),
                                                        TextInput::make('value')
                                                            ->label('Valor')
                                                            ->required(),
                                                    ])
                                                    ->columnSpanFull()
                                                    ->addActionLabel('Adicionar campo')
                                                    ->deletable(fn() => Auth::user()->is_admin)
                                                    ->reorderable(),
                                            ]),
                                        Section::make('cofins_group')
                                            ->label('COFINS')
                                            ->columns([
                                                'sm' => 1,
                                                'md' => 2,
                                                'lg' => 2,
                                            ])
                                            ->collapsible()
                                            ->persistCollapsed()
                                            ->columnSpanFull()
                                            ->schema([
                                                Repeater::make('cofins')
                                                    ->hideLabel()
                                                    ->schema([
                                                        TextInput::make('key')
                                                            ->label('Chave')
                                                            ->required(),
                                                        TextInput::make('value')
                                                            ->label('Valor')
                                                            ->required(),
                                                    ])
                                                    ->columnSpanFull()
                                                    ->addActionLabel('Adicionar campo')
                                                    ->deletable(fn() => Auth::user()->is_admin)
                                                    ->reorderable(),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
