<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Schemas;

use App\Enum\Product\Unit;
use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\EditProductionOrder;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\RelationManagers\ItemsRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        Select::make('customer_id')
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
                            ->required(fn (Get $get): bool => $get('destination_type') === DestinationType::DIRECT_DELIVERY->value)
                            ->helperText('Obrigatório apenas para destino "Entrega Direta".'),
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
                            ->default(fn() => Filament::getTenant()->id),
                    ]),
                Section::make('Itens da Produção')
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->schema([
                        Livewire::make(ItemsRelationManager::class, fn(ProductionOrder $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => EditProductionOrder::class,
                        ])
                            ->key('items-relation-manager')
                            ->columnSpanFull(),
                    ]),
                Section::make('Anexos')
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->schema([
                        Livewire::make(AttachmentsRelationManager::class, fn(ProductionOrder $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => EditProductionOrder::class,
                        ])
                            ->key('attachments-relation-manager')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
