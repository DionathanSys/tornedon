<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class RequisitionForm
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
                Section::make('Dados da Requisição')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::OPEN->value)
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('customer_id')
                            ->label('Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(function () {
                                return \App\Models\Partner::whereHas('companies', function ($query) {
                                        $query->where('companies.id', Filament::getTenant()->id)
                                            ->whereJsonContains('company_partner.type', 'customer')
                                            ->where('company_partner.is_active', true);
                                    })
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('sale_date')
                            ->label('Data da Venda')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(now())
                            ->required()
                            ->native(false),
                        Select::make('salesperson_id')
                            ->label('Vendedor')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->relationship(
                                name: 'salesperson',
                                titleAttribute: 'name',
                            )
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('equipment_id')
                            ->label('Equipamento')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship(
                                name: 'equipment',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query
                                    ->where('company_id', Filament::getTenant()->id)
                                    ->orderBy('name')
                            )
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ]),
                Section::make('Pagamento e Entrega')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable(),
                        Select::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable(),
                        Money::make('discount_amount')
                            ->label('Desconto')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(0)
                            ->prefix('R$'),
                        DatePicker::make('delivery_date')
                            ->label('Data de Entrega')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->native(false)
                            ->nullable(),
                        TextInput::make('delivery_address')
                            ->label('Endereço de Entrega')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->maxLength(255),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(3)
                            ->maxLength(1000),
                    ]),
                Section::make('Itens da Requisição')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->columns([
                                'sm' => 1,
                                'md' => 6,
                                'lg' => 12,
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label('Produto')
                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                    ->relationship(
                                        name: 'product',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn ($query) => $query
                                            ->where('company_id', Filament::getTenant()->id)
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state) {
                                            $product = \App\Models\Product::find($state);
                                            if ($product) {
                                                $set('unit_of_measure', $product->unit->value ?? 'UN');
                                            }
                                        }
                                    }),
                                TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->required()
                                    ->default(1),
                                Select::make('unit_of_measure')
                                    ->label('Unidade')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->options(Unit::toSelectArray())
                                    ->native(false)
                                    ->required()
                                    ->default('UN'),
                                Money::make('unit_price')
                                    ->label('Preço Unit.')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->required()
                                    ->default(0)
                                    ->prefix('R$'),
                                Money::make('discount_amount')
                                    ->label('Desconto')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->default(0)
                                    ->prefix('R$'),
                                Textarea::make('observations')
                                    ->label('Obs.')
                                    ->columnSpan(['md' => 6, 'lg' => 12])
                                    ->rows(1)
                                    ->maxLength(500),
                            ])
                            ->defaultItems(1)
                            ->addActionLabel('Adicionar Item')
                            ->reorderable(false)
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string =>
                                isset($state['product_id'])
                                    ? \App\Models\Product::find($state['product_id'])?->name
                                    : null
                            ),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
