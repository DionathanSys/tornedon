<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Schemas;

use App\Enum\Product\Unit;
use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionOrderForm
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
                Section::make('Dados da Ordem de Produção')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('production_order_number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::QUEUED->value)
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('quote_id')
                            ->label('Orçamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->relationship(
                                name: 'quote',
                                titleAttribute: 'quote_number',
                                modifyQueryUsing: fn ($query) => $query
                                    ->where('company_id', Filament::getTenant()->id)
                                    ->where('status', 'approved')
                                    ->whereDoesntHave('productionOrder')
                                    ->orderBy('quote_number', 'desc')
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Selecione um orçamento aprovado ou deixe vazio para criar manualmente'),
                        Select::make('partner_id')
                            ->label('Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 2])
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
                        Select::make('priority')
                            ->label('Prioridade')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Priority::toSelectArray())
                            ->native(false)
                            ->default(Priority::NORMAL->value)
                            ->required(),
                        Select::make('destination_type')
                            ->label('Destino')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(DestinationType::toSelectArray())
                            ->native(false)
                            ->default(DestinationType::STOCK->value)
                            ->required()
                            ->helperText('Estoque: entrada automática. Entrega Direta: cria requisição'),
                        Select::make('assigned_operator')
                            ->label('Operador')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->relationship(
                                name: 'assignedOperator',
                                titleAttribute: 'name',
                            )
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        TextInput::make('assigned_machine')
                            ->label('Máquina/Equipamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(255)
                            ->nullable(),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(3)
                            ->maxLength(1000),
                        Hidden::make('company_id')
                            ->default(fn () => Filament::getTenant()->id),
                    ]),
                Section::make('Itens da Produção')
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
                                    ->nullable(),
                                TextInput::make('description')
                                    ->label('Descrição')
                                    ->columnSpan(['md' => 4, 'lg' => 5])
                                    ->required()
                                    ->maxLength(500),
                                TextInput::make('quantity')
                                    ->label('Qtd Solicitada')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->columnStart(1)
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required(),
                                Select::make('unit_of_measure')
                                    ->label('Unid.')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->options(Unit::toSelectArray())
                                    ->native(false)
                                    ->default('UN')
                                    ->required(),
                                TextInput::make('quantity_produced')
                                    ->label('Qtd Produzida')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->visibleOn('edit'),
                                TextInput::make('quantity_approved')
                                    ->label('Qtd Aprovada')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->visibleOn('edit'),
                                TextInput::make('quantity_rejected')
                                    ->label('Qtd Rejeitada')
                                    ->columnSpan(['md' => 1, 'lg' => 1])
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->visibleOn('edit'),
                                KeyValue::make('technical_specifications')
                                    ->label('Especificações Técnicas')
                                    ->columnSpan(['md' => 6, 'lg' => 6])
                                    ->columnStart(1)
                                    ->keyLabel('Propriedade')
                                    ->valueLabel('Valor')
                                    ->addActionLabel('Adicionar especificação')
                                    ->reorderable(),
                                Textarea::make('production_notes')
                                    ->label('Notas de Produção')
                                    ->columnSpan(['md' => 6, 'lg' => 6])
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->visibleOn('edit'),
                                Textarea::make('qc_notes')
                                    ->label('Notas de Controle de Qualidade')
                                    ->columnSpan(['md' => 6, 'lg' => 6])
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->visibleOn('edit'),
                                TextInput::make('actual_production_hours')
                                    ->label('Horas Reais de Produção')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('h')
                                    ->visibleOn('edit'),
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
