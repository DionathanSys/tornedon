<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Schemas;

use App\Enum\Product\Unit;
use App\Models\Category;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                Section::make('Informações do Produto')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('product_code')
                            ->label('Código do Produto')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(60)
                            ->visibleOn('edit')
                            ->disabled(),
                        TextInput::make('name')
                            ->label('Nome')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(3)
                            ->maxLength(500),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship(
                                name: 'category',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query
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
                                    }),
                                Textarea::make('description')
                                    ->label('Descrição')
                                    ->rows(2)
                                    ->maxLength(500),
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
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->inline(false)
                            ->default(true),
                    ]),
                Section::make('Precificação')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
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
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
