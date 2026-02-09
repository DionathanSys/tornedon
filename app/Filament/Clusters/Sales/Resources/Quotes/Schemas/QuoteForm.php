<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas;

use App\Enum\Product\Unit;
use App\Enum\Quote\Status;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class QuoteForm
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
                Section::make('Dados do Orçamento')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quote_number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::DRAFT->value)
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('partner_id')
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
                        DatePicker::make('valid_until')
                            ->label('Válido até')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->default(now()->addDays(30))
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->columnSpan(['md' => 4, 'lg' => 6])
                            ->rows(2)
                            ->maxLength(1000),
                        Textarea::make('observations')
                            ->label('Observações Internas')
                            ->columnSpan(['md' => 4, 'lg' => 4])
                            ->rows(2)
                            ->maxLength(1000),
                        Textarea::make('customer_observations')
                            ->label('Observações do Cliente')
                            ->columnSpan(['md' => 4, 'lg' => 4])
                            ->rows(2)
                            ->maxLength(1000),
                        Hidden::make('company_id')
                            ->default(fn () => Filament::getTenant()->id),
                    ]),
                Section::make('Anexos')
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        FileUpload::make('technical_drawings')
                            ->label('Desenhos Técnicos')
                            ->columnSpanFull()
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->acceptedFileTypes(['application/pdf', 'image/*', 'application/dwg', 'application/dxf'])
                            ->maxSize(10240)
                            ->helperText('Formatos aceitos: PDF, imagens, DWG, DXF. Máximo 10MB por arquivo.'),
                        FileUpload::make('specifications')
                            ->label('Especificações')
                            ->columnSpanFull()
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->acceptedFileTypes(['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/*'])
                            ->maxSize(10240),
                        FileUpload::make('photos')
                            ->label('Fotos')
                            ->columnSpanFull()
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->image()
                            ->imageEditor()
                            ->maxSize(5120),
                    ]),
                Section::make('Itens do Orçamento')
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
                                    ->columnSpan(['md' => 2, 'lg' => 3])
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
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->label('Nome')
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('unit')
                                            ->label('Unidade')
                                            ->options(Unit::toSelectArray())
                                            ->required()
                                            ->native(false),
                                    ])
                                    ->nullable(),
                                TextInput::make('description')
                                    ->label('Descrição')
                                    ->columnSpan(['md' => 4, 'lg' => 5])
                                    ->required()
                                    ->maxLength(500),
                                TextInput::make('quantity')
                                    ->label('Qtd')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required(),
                                Select::make('unit_of_measure')
                                    ->label('Unid.')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->options(Unit::toSelectArray())
                                    ->native(false)
                                    ->default('UN')
                                    ->required(),
                                Money::make('unit_price')
                                    ->label('Preço Unit.')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->prefix('R$')
                                    ->default(0)
                                    ->required(),
                                Money::make('discount_amount')
                                    ->label('Desconto')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->prefix('R$')
                                    ->default(0),
                                KeyValue::make('technical_specifications')
                                    ->label('Especificações Técnicas')
                                    ->columnSpan(['md' => 6, 'lg' => 6])
                                    ->keyLabel('Propriedade')
                                    ->valueLabel('Valor')
                                    ->addActionLabel('Adicionar especificação')
                                    ->reorderable(),
                                TextInput::make('estimated_production_hours')
                                    ->label('Horas Estimadas')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('h'),
                                Money::make('material_cost')
                                    ->label('Custo Material')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->prefix('R$')
                                    ->default(0),
                                Money::make('labor_cost')
                                    ->label('Custo M.O.')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->prefix('R$')
                                    ->default(0),
                            ])
                            ->defaultItems(1)
                            ->addActionLabel('Adicionar item')
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string => $state['description'] ?? 'Item')
                            ->minItems(1)
                            ->required(),
                    ]),
            ]);
    }
}
